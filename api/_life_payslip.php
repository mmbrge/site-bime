<?php
// فایل: api/_life_payslip.php
// «فیشِ پرداخت» (پیش از پرداخت) برای هر قسطِ بیمه عمر: اطلاعاتِ بیمه‌گذار و بیمه‌نامه، مبلغِ قابلِ پرداخت و مهلت،
// روش‌های پرداخت (کارت، شبا، حساب، لینکِ پرداخت)، نحوه‌ی اطلاع‌رسانی بعد از واریز و کارشناسِ پیگیری.
// قالبِ پیش‌فرضِ سامانه (HTMLِ آماده‌ی چاپ/PDF/عکس + Word) یا قالبِ Wordِ دلخواه (life_templates با kind = 'slip').
// تنظیمات در system_settings با کلیدِ life_payslip (JSON) و لوگوی دلخواه در tmpl_invoice/life.

const LIFE_SLIP_ACCENTS = [
    'indigo'  => ['نیلی', '#4338ca', '#0ea5e9'],
    'emerald' => ['سبز', '#047857', '#14b8a6'],
    'rose'    => ['سرخابی', '#be123c', '#f97316'],
    'violet'  => ['بنفش', '#6d28d9', '#db2777'],
    'navy'    => ['سرمه‌ای', '#1e3a8a', '#0891b2'],
    'gold'    => ['طلایی', '#92400e', '#d97706'],
];
const LIFE_SLIP_ACC_TYPES = ['card' => 'شماره کارت', 'sheba' => 'شماره شبا', 'account' => 'شماره حساب', 'link' => 'لینکِ پرداختِ اینترنتی'];
const LIFE_SLIP_CH_TYPES = ['bale' => ['بله', 'fa-comment-dots'], 'whatsapp' => ['واتساپ', 'fa-whatsapp'], 'telegram' => ['تلگرام', 'fa-telegram'], 'eitaa' => ['ایتا', 'fa-comment'],
                            'sms' => ['پیامک', 'fa-comment-sms'], 'phone' => ['تماس', 'fa-phone'], 'email' => ['ایمیل', 'fa-envelope']];

function life_slip_defaults() {
    return [
        'title' => 'فیشِ پرداختِ قسطِ بیمه‌نامه‌ی عمر', 'company' => 'بیمه با ما', 'subtitle' => '',
        'logo' => 'brand', 'logo_file' => '', 'accent' => 'indigo',
        'accounts' => [],
        'pay_text' => "مبلغِ قابلِ پرداخت را تا پایانِ مهلت به یکی از حساب‌های زیر واریز کنید.\nلطفاً در توضیحاتِ واریز، شماره‌ی بیمه‌نامه یا نامِ بیمه‌گذار را بنویسید.",
        'notify_text' => 'بعد از واریز، تصویرِ رسیدِ بانکی را همراه با نام و شماره‌ی بیمه‌نامه برای کارشناس بفرستید تا پرداخت در پرونده ثبت شود.',
        'channels' => [],
        'expert_name' => '', 'expert_title' => 'کارشناسِ بیمه عمر', 'expert_mobile' => '',
        'note' => 'پرداختِ به‌موقعِ اقساط، پوشش‌های بیمه‌نامه و اندوخته‌ی شما را حفظ می‌کند.',
        'show_schedule' => true, 'show_overdue' => true, 'deadline_days' => 3, 'default_template' => 'builtin',
    ];
}
function life_slip_settings($pdo) {
    $s = json_decode((string)life_setting($pdo, 'life_payslip', ''), true);
    $s = is_array($s) ? array_merge(life_slip_defaults(), $s) : life_slip_defaults();
    if (!isset(LIFE_SLIP_ACCENTS[$s['accent']])) $s['accent'] = 'indigo';
    return $s;
}
function life_slip_settings_save($pdo, array $in) {
    $s = life_slip_settings($pdo);
    $str = function ($k, $max) use (&$s, $in) { if (array_key_exists($k, $in)) $s[$k] = mb_substr(trim(str_replace("\r", '', (string)$in[$k])), 0, $max); };
    foreach (['title' => 120, 'company' => 120, 'subtitle' => 190, 'pay_text' => 1500, 'notify_text' => 1500, 'expert_name' => 120, 'expert_title' => 120, 'note' => 1500] as $k => $m) $str($k, $m);
    if (isset($in['expert_mobile'])) $s['expert_mobile'] = mb_substr(preg_replace('/[^\d+\-\s]/', '', p2e_digits((string)$in['expert_mobile'])), 0, 30);
    if (isset($in['logo']) && in_array($in['logo'], ['brand', 'custom', 'none'], true)) $s['logo'] = $in['logo'];
    if (isset($in['accent']) && isset(LIFE_SLIP_ACCENTS[$in['accent']])) $s['accent'] = $in['accent'];
    foreach (['show_schedule', 'show_overdue'] as $k) if (array_key_exists($k, $in)) $s[$k] = !empty($in[$k]);
    if (isset($in['deadline_days'])) $s['deadline_days'] = max(0, min(60, intval($in['deadline_days'])));
    if (isset($in['default_template'])) $s['default_template'] = $in['default_template'] === 'builtin' ? 'builtin' : (string)intval($in['default_template']);
    if (isset($in['accounts']) && is_array($in['accounts'])) {
        $s['accounts'] = [];
        foreach (array_slice($in['accounts'], 0, 8) as $a) {
            $type = isset(LIFE_SLIP_ACC_TYPES[$a['type'] ?? '']) ? $a['type'] : 'card';
            $num = trim(p2e_digits((string)($a['number'] ?? '')));
            if ($type === 'card' || $type === 'account') $num = preg_replace('/[^\d\-]/', '', $num);
            if ($type === 'sheba') { $num = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $num)); if ($num !== '' && strpos($num, 'IR') !== 0) $num = 'IR' . $num; }
            if ($type === 'link' && $num !== '' && !preg_match('~^https?://~i', $num)) $num = 'https://' . $num;
            if ($num === '') continue;
            $s['accounts'][] = ['type' => $type, 'number' => mb_substr($num, 0, 300), 'bank' => mb_substr(trim((string)($a['bank'] ?? '')), 0, 80), 'owner' => mb_substr(trim((string)($a['owner'] ?? '')), 0, 120)];
        }
    }
    if (isset($in['channels']) && is_array($in['channels'])) {
        $s['channels'] = [];
        foreach (array_slice($in['channels'], 0, 8) as $c) {
            $type = isset(LIFE_SLIP_CH_TYPES[$c['type'] ?? '']) ? $c['type'] : 'phone';
            $val = mb_substr(trim(p2e_digits((string)($c['value'] ?? ''))), 0, 190);
            if ($val !== '') $s['channels'][] = ['type' => $type, 'value' => $val];
        }
    }
    life_setting_set($pdo, 'life_payslip', json_encode($s, JSON_UNESCAPED_UNICODE));
    return $s;
}

// لوگو: «brand» = لوگوی سربرگِ سایت، «custom» = لوگوی بارگذاری‌شده برای فیش، «none» = بدونِ لوگو
function life_slip_logo_path($pdo, array $s) {
    if ($s['logo'] === 'custom' && $s['logo_file'] !== '') { $p = life_root() . '/' . $s['logo_file']; return is_file($p) ? $p : null; }
    if ($s['logo'] === 'brand') {
        $lg = json_decode((string)life_setting($pdo, 'brand_logo', ''), true);
        if (is_array($lg) && !empty($lg['file'])) { $p = life_root() . '/uploads/brand/' . basename($lg['file']); return is_file($p) ? $p : null; }
    }
    return null;
}
function life_slip_logo_data($pdo, array $s) {
    $p = life_slip_logo_path($pdo, $s);
    if (!$p || filesize($p) > 2 * 1048576) return '';
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'gif' => 'image/gif'][$ext] ?? '';
    return $mime ? 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($p)) : '';
}

// قالب‌های Wordِ فیش (life_templates با kind = 'slip')
function life_slip_templates($pdo) {
    $out = [];
    foreach ($pdo->query("SELECT * FROM life_templates WHERE kind = 'slip' ORDER BY id") as $t) $out[] = ['id' => intval($t['id']), 'name' => $t['name'], 'file' => basename((string)$t['file_path'])];
    return $out;
}
function life_slip_template_row($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM life_templates WHERE id = ? AND kind = 'slip'");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function life_slip_codes() {
    return [
        'شماره_فیش' => 'شماره‌ی فیش', 'تاریخ_امروز' => 'تاریخِ صدورِ فیش', 'عنوان' => 'عنوانِ فیش (از تنظیمات)', 'نام_مجموعه' => 'نامِ مجموعه',
        'نام_بیمه_گذار' => 'نامِ بیمه‌گذار', 'کد_ملی' => 'کد ملیِ بیمه‌گذار', 'موبایل' => 'موبایلِ بیمه‌گذار', 'شماره_بیمه_نامه' => 'شماره‌ی بیمه‌نامه', 'بیمه_شده' => 'بیمه‌شده‌ی اصلی',
        'طرح' => 'طرح / واریانت', 'روش_پرداخت_حق_بیمه' => 'ماهانه / سه‌ماهه / ...', 'شناسه_واریز' => 'شناسه‌ی واریز', 'شماره_قسط' => 'شماره‌ی قسط', 'سررسید' => 'سررسیدِ قسط',
        'مبلغ_قسط' => 'مبلغِ قسط (ریال)', 'پرداخت_شده' => 'پرداخت‌شده‌ی همین قسط', 'مبلغ_قابل_پرداخت' => 'مبلغِ قابلِ پرداخت (ریال)', 'مبلغ_حروف' => 'مبلغِ قابلِ پرداخت به حروف',
        'مهلت_پرداخت' => 'مهلتِ پرداخت', 'معوقات' => 'جمعِ اقساطِ معوقِ قبلی (ریال)', 'حساب_ها' => 'همه‌ی حساب‌ها (هر کدام یک خط)', 'شماره_کارت' => 'اولین شماره کارت',
        'شماره_شبا' => 'اولین شماره شبا', 'صاحب_حساب' => 'نامِ صاحبِ حساب (اولین حساب)', 'بانک' => 'نامِ بانک (اولین حساب)', 'لینک_پرداخت' => 'لینکِ پرداختِ اینترنتی',
        'نحوه_پرداخت' => 'متنِ نحوه‌ی پرداخت', 'نحوه_اطلاع_رسانی' => 'متنِ نحوه‌ی اطلاع‌رسانی', 'راه_های_ارتباط' => 'راه‌های ارسالِ رسید (بله، واتساپ، ...)',
        'کارشناس' => 'نامِ کارشناس', 'سمت_کارشناس' => 'سمتِ کارشناس', 'موبایل_کارشناس' => 'موبایلِ کارشناس', 'توضیح' => 'توضیحِ همین فیش', 'متن_پایانی' => 'متنِ پایانی (از تنظیمات)',
        'جدول_اقساط' => 'جدولِ اقساطِ باقی‌مانده (کلِ پاراگراف با جدول جایگزین می‌شود)',
    ];
}

// داده‌ی فیش: قسط، بیمه‌نامه، مبلغ (پیش‌فرض: مانده‌ی همین قسط؛ با «معوقات» جمعِ اقساطِ معوقِ قبلی هم اضافه می‌شود)، مهلت
function life_slip_data($pdo, $instId, array $opt, $uid) {
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE id = ?");
    $st->execute([intval($instId)]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = life_detail($pdo, intval($row['policy_id']));
    if (!$d) return null;
    $cur = null; $over = []; $rest = [];
    foreach ($d['insts'] as $i) {
        if ($i['id'] === intval($instId)) $cur = $i;
        elseif ($i['rem'] > 0 && $i['inst_no'] < intval($row['inst_no']) && ($i['days'] ?? 0) > 0) $over[] = $i;
        if ($i['rem'] > 0) $rest[] = $i;
    }
    if (!$cur) return null;
    $s = life_slip_settings($pdo);
    $withOver = !empty($opt['with_overdue']) && $over;
    $overSum = array_sum(array_column($over, 'rem'));
    $amount = isset($opt['amount']) && intval(p2e_digits((string)$opt['amount'])) > 0 ? intval(preg_replace('/\D/', '', p2e_digits((string)$opt['amount'])))
            : ($cur['rem'] > 0 ? $cur['rem'] : $cur['amount']) + ($withOver ? $overSum : 0);
    // مهلت: ورودی، وگرنه سررسید (اگر نگذشته) یا امروز + چند روز
    $dl = trim(p2e_digits((string)($opt['deadline'] ?? '')));
    if (!preg_match('~^1[34]\d\d/\d{1,2}/\d{1,2}$~', $dl)) {
        $dueG = $row['due_g'] ? strtotime($row['due_g']) : 0;
        if ($dueG && $dueG >= strtotime(date('Y-m-d'))) $dl = (string)$cur['due_j'];
        else { [$jy, $jm, $jd] = jalali_from_gregorian_ts(time() + intval($s['deadline_days']) * 86400); $dl = sprintf('%04d/%02d/%02d', $jy, $jm, $jd); }
    }
    $today = life_today_j();
    return ['p' => $d['policy'], 'inst' => $cur, 'insts' => $d['insts'], 'rest' => $rest, 'over' => $over, 'over_sum' => $overSum, 'with_over' => $withOver, 'amount' => $amount,
            'deadline' => $dl, 'note' => mb_substr(trim((string)($opt['note'] ?? '')), 0, 600), 's' => $s, 'today' => $today, 'user' => life_user_name($pdo, $uid),
            'no' => 'F' . intval($row['policy_id']) . '-' . intval($cur['inst_no']) . '-' . str_replace('/', '', substr($today, 2))];
}
function life_slip_card_fmt($n) { $n = preg_replace('/\D/', '', $n); return strlen($n) === 16 ? implode('-', str_split($n, 4)) : $n; }
function life_slip_sheba_fmt($n) { return trim(chunk_split($n, 4, ' ')); }
function life_slip_vars(array $r) {
    $p = $r['p']; $i = $r['inst']; $s = $r['s'];
    $first = function ($type, $key = 'number') use ($s) { foreach ($s['accounts'] as $a) if ($a['type'] === $type) return $a[$key]; return ''; };
    $accLines = [];
    foreach ($s['accounts'] as $a) {
        $num = $a['type'] === 'card' ? life_slip_card_fmt($a['number']) : ($a['type'] === 'sheba' ? life_slip_sheba_fmt($a['number']) : $a['number']);
        $accLines[] = LIFE_SLIP_ACC_TYPES[$a['type']] . ': ' . $num . ($a['bank'] !== '' ? ' — ' . $a['bank'] : '') . ($a['owner'] !== '' ? ' — به نامِ ' . $a['owner'] : '');
    }
    $ch = array_map(function ($c) { return LIFE_SLIP_CH_TYPES[$c['type']][0] . ': ' . $c['value']; }, $s['channels']);
    $words = function_exists('fin_num_to_words') ? fin_num_to_words($r['amount']) : '';
    $owner = ''; $bank = '';
    foreach ($s['accounts'] as $a) if ($a['type'] !== 'link') { $owner = $a['owner']; $bank = $a['bank']; break; }
    return [
        'شماره_فیش' => $r['no'], 'تاریخ_امروز' => life_fa($r['today']), 'عنوان' => $s['title'], 'نام_مجموعه' => $s['company'],
        'نام_بیمه_گذار' => (string)$p['holder_name'], 'کد_ملی' => life_fa($p['holder_nid']), 'موبایل' => life_fa($p['holder_mobile']), 'شماره_بیمه_نامه' => life_fa($p['policy_no']),
        'بیمه_شده' => (string)$p['insured_name'], 'طرح' => trim($p['field_name'] . ' ' . $p['variant']), 'روش_پرداخت_حق_بیمه' => (string)$p['pay_method'],
        'شناسه_واریز' => life_fa($i['pay_id'] ?: $p['pay_id']), 'شماره_قسط' => life_fa($i['inst_no']), 'سررسید' => life_fa($i['due_j']), 'مبلغ_قسط' => life_money($i['amount']),
        'پرداخت_شده' => life_money($i['eff_paid']), 'مبلغ_قابل_پرداخت' => life_money($r['amount']), 'مبلغ_حروف' => $words ? $words . ' ریال' : '', 'مهلت_پرداخت' => life_fa($r['deadline']),
        'معوقات' => $r['over_sum'] ? life_money($r['over_sum']) : '', 'حساب_ها' => implode("\n", $accLines),
        'شماره_کارت' => life_slip_card_fmt($first('card')), 'شماره_شبا' => life_slip_sheba_fmt($first('sheba')), 'صاحب_حساب' => $owner, 'بانک' => $bank, 'لینک_پرداخت' => $first('link'),
        'نحوه_پرداخت' => $s['pay_text'], 'نحوه_اطلاع_رسانی' => $s['notify_text'], 'راه_های_ارتباط' => implode("\n", $ch),
        'کارشناس' => $s['expert_name'], 'سمت_کارشناس' => $s['expert_title'], 'موبایل_کارشناس' => life_fa($s['expert_mobile']), 'توضیح' => $r['note'], 'متن_پایانی' => $s['note'],
    ];
}

// متنِ ساده‌ی فیش (برای پیامک، واتساپ، بله)
function life_slip_text(array $r) {
    $v = life_slip_vars($r);
    $L = [];
    $L[] = '🧾 ' . $v['عنوان'] . ($v['نام_مجموعه'] !== '' ? ' | ' . $v['نام_مجموعه'] : '');
    $L[] = '';
    $L[] = 'بیمه‌گذارِ گرامی ' . $v['نام_بیمه_گذار'];
    $L[] = 'بیمه‌نامه: ' . $v['شماره_بیمه_نامه'];
    $L[] = 'قسطِ ' . $v['شماره_قسط'] . ' · سررسید ' . $v['سررسید'];
    if ($r['with_over']) $L[] = 'شاملِ اقساطِ معوقِ قبلی: ' . $v['معوقات'] . ' ریال';
    $L[] = '💰 مبلغِ قابلِ پرداخت: ' . $v['مبلغ_قابل_پرداخت'] . ' ریال';
    $L[] = '⏳ مهلتِ پرداخت: ' . $v['مهلت_پرداخت'];
    if ($v['نحوه_پرداخت'] !== '') { $L[] = ''; $L[] = $v['نحوه_پرداخت']; }
    if ($v['حساب_ها'] !== '') { $L[] = ''; $L[] = '💳 روش‌های پرداخت:'; foreach (explode("\n", $v['حساب_ها']) as $x) $L[] = '• ' . $x; }
    if ($v['نحوه_اطلاع_رسانی'] !== '') { $L[] = ''; $L[] = '📩 ' . $v['نحوه_اطلاع_رسانی']; }
    if ($v['راه_های_ارتباط'] !== '') foreach (explode("\n", $v['راه_های_ارتباط']) as $x) $L[] = '• ' . $x;
    if ($v['کارشناس'] !== '' || $v['موبایل_کارشناس'] !== '') { $L[] = ''; $L[] = '👤 ' . trim($v['سمت_کارشناس'] . ': ' . $v['کارشناس'] . ($v['موبایل_کارشناس'] !== '' ? ' · ' . $v['موبایل_کارشناس'] : ''), ': '); }
    if ($v['توضیح'] !== '') { $L[] = ''; $L[] = $v['توضیح']; }
    $L[] = ''; $L[] = 'شماره‌ی فیش: ' . $v['شماره_فیش'];
    return implode("\n", $L);
}

// ---------------------------------------------------------------------
//  قالبِ پیش‌فرضِ سامانه: HTML (چاپ، PDF، عکس)
// ---------------------------------------------------------------------
function life_slip_html($pdo, array $r) {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $nl = function ($v) use ($h) { return nl2br($h($v)); };
    $s = $r['s']; $v = life_slip_vars($r); $i = $r['inst']; $p = $r['p'];
    [, $c1, $c2] = LIFE_SLIP_ACCENTS[$s['accent']];
    $logo = life_slip_logo_data($pdo, $s);
    // کارت‌های حساب
    $accHtml = '';
    foreach ($s['accounts'] as $a) {
        $t = $a['type'];
        if ($t === 'link') {
            $accHtml .= '<a class="acc lnk" href="' . $h($a['number']) . '" target="_blank" rel="noopener"><div class="acc-h"><span class="acc-t">پرداختِ اینترنتی</span>' . ($a['bank'] !== '' ? '<span class="acc-b">' . $h($a['bank']) . '</span>' : '') . '</div>'
                      . '<div class="acc-n" dir="ltr">' . $h(preg_replace('~^https?://~', '', $a['number'])) . '</div><div class="acc-o">برای پرداخت بزنید ←</div></a>';
            continue;
        }
        $show = $t === 'card' ? life_slip_card_fmt($a['number']) : ($t === 'sheba' ? life_slip_sheba_fmt($a['number']) : $a['number']);
        $accHtml .= '<div class="acc ' . $t . '"><div class="acc-h"><span class="acc-t">' . $h(LIFE_SLIP_ACC_TYPES[$t]) . '</span>' . ($a['bank'] !== '' ? '<span class="acc-b">' . $h($a['bank']) . '</span>' : '') . '</div>'
                  . '<div class="acc-n" dir="ltr">' . $h($show) . '</div><div class="acc-f">' . ($a['owner'] !== '' ? '<span class="acc-o">به نامِ ' . $h($a['owner']) . '</span>' : '<span></span>')
                  . '<button type="button" class="cp" data-cp="' . $h($a['number']) . '">کپی</button></div>' . ($t === 'card' ? '<i class="chip"></i>' : '') . '</div>';
    }
    $chHtml = '';
    foreach ($s['channels'] as $c) {
        $val = $c['value']; $href = '';
        $digits = preg_replace('/\D/', '', $val);
        if ($c['type'] === 'phone') $href = 'tel:' . $digits;
        elseif ($c['type'] === 'sms') $href = 'sms:' . $digits;
        elseif ($c['type'] === 'whatsapp' && $digits !== '') $href = 'https://wa.me/' . (strpos($digits, '0') === 0 ? '98' . substr($digits, 1) : $digits);
        elseif ($c['type'] === 'email') $href = 'mailto:' . $val;
        elseif (preg_match('~^https?://~', $val)) $href = $val;
        elseif ($c['type'] === 'telegram' && preg_match('~^@?([\w]{4,})$~', $val, $m)) $href = 'https://t.me/' . $m[1];
        elseif ($c['type'] === 'bale' && preg_match('~^@?([\w]{4,})$~', $val, $m) && !ctype_digit($m[1])) $href = 'https://ble.ir/' . $m[1];
        $chHtml .= ($href ? '<a class="ch ' . $c['type'] . '" href="' . $h($href) . '" target="_blank" rel="noopener">' : '<span class="ch ' . $c['type'] . '">')
                 . '<b>' . $h(LIFE_SLIP_CH_TYPES[$c['type']][0]) . '</b><span dir="ltr">' . $h(life_fa($val)) . '</span>' . ($href ? '</a>' : '</span>');
    }
    // اقساطِ باقی‌مانده
    $sched = '';
    if ($s['show_schedule'] && count($r['rest']) > 0) {
        $rows = '';
        foreach (array_slice($r['rest'], 0, 12) as $x) {
            $on = $x['id'] === $i['id'];
            $rows .= '<tr' . ($on ? ' class="on"' : '') . '><td>' . life_fa($x['inst_no']) . '</td><td>' . life_fa($x['due_j']) . '</td><td>' . life_money($x['amount']) . '</td><td>' . life_money($x['rem']) . '</td><td>'
                   . ($x['days'] > 0 ? '<span class="late">' . life_fa($x['days']) . ' روز گذشته</span>' : ($on ? 'همین فیش' : '—')) . '</td></tr>';
        }
        $sched = '<section class="box"><h3>اقساطِ پرداخت‌نشده</h3><div class="tw"><table><thead><tr><th>قسط</th><th>سررسید</th><th>مبلغ (ریال)</th><th>مانده (ریال)</th><th>وضعیت</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }
    $overBox = '';
    if ($s['show_overdue'] && $r['over'] && !$r['with_over']) {
        $overBox = '<div class="warn"><b>توجه:</b> ' . life_fa(count($r['over'])) . ' قسطِ قبلی به مبلغِ ' . life_money($r['over_sum']) . ' ریال هنوز پرداخت نشده است؛ برای حفظِ پوشش‌های بیمه‌نامه آن‌ها را هم پرداخت کنید.</div>';
    }
    $expert = '';
    if ($s['expert_name'] !== '' || $s['expert_mobile'] !== '') {
        $mob = preg_replace('/\D/', '', $s['expert_mobile']);
        $expert = '<section class="exp"><div class="av">' . $h(mb_substr($s['expert_name'] ?: 'ک', 0, 1)) . '</div><div class="ex-i"><small>' . $h($s['expert_title'] ?: 'کارشناس') . '</small><b>' . $h($s['expert_name']) . '</b></div>'
                . ($mob !== '' ? '<a class="ex-c" href="tel:' . $h($mob) . '" dir="ltr">' . $h(life_fa($s['expert_mobile'])) . '</a>' : '') . '</section>';
    }
    $title = $s['title'] ?: 'فیشِ پرداختِ قسط';
    $text = life_slip_text($r);
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>' . $h($title . ' - ' . $v['نام_بیمه_گذار'] . ' - قسط ' . $v['شماره_قسط']) . '</title>
<style>
@font-face{font-family:Vazir;src:url(../Font/Vazir-Regular.woff2) format("woff2");font-weight:400}
@font-face{font-family:Vazir;src:url(../Font/Vazir-Bold.woff2) format("woff2");font-weight:700}
@font-face{font-family:Vazir;src:url(../Font/Vazir-Black.woff2) format("woff2");font-weight:900}
:root{--a:' . $c1 . ';--b:' . $c2 . '}
*{box-sizing:border-box}html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:Vazir,Vazirmatn,Tahoma,sans-serif;background:#e9edf5;margin:0;padding:22px 12px;color:#0f172a}
.bar{max-width:760px;margin:0 auto 12px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.bar button{font:inherit;font-size:12.5px;font-weight:700;border:0;border-radius:12px;padding:9px 15px;cursor:pointer;background:var(--a);color:#fff;box-shadow:0 8px 18px -10px var(--a)}
.bar button.g{background:#fff;color:#334155;border:1px solid #cbd5e1;box-shadow:none}
.pg{max-width:760px;margin:0 auto;background:#fff;border-radius:26px;overflow:hidden;box-shadow:0 30px 60px -28px rgba(15,23,42,.45)}
.hd{position:relative;padding:26px 28px 70px;color:#fff;background:linear-gradient(125deg,var(--a),var(--b));overflow:hidden}
.hd:before,.hd:after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.1)}
.hd:before{width:260px;height:260px;left:-80px;top:-120px}.hd:after{width:160px;height:160px;left:140px;bottom:-90px;background:rgba(255,255,255,.07)}
.hd-r{position:relative;display:flex;align-items:center;gap:14px}
.lg{width:62px;height:62px;border-radius:18px;background:#fff;display:flex;align-items:center;justify-content:center;padding:7px;flex-shrink:0;box-shadow:0 10px 24px -10px rgba(0,0,0,.45)}
.lg img{max-width:100%;max-height:100%;object-fit:contain}
.co b{display:block;font-size:19px;font-weight:900}.co small{display:block;font-size:11.5px;opacity:.85;margin-top:2px}
.meta{margin-right:auto;text-align:left;font-size:11px;line-height:1.9;opacity:.92}.meta b{font-weight:900}
.hd h1{position:relative;margin:18px 0 0;font-size:21px;font-weight:900;letter-spacing:-.2px}
.amt{position:relative;margin:-50px 22px 0;background:#fff;border-radius:22px;padding:18px 20px;box-shadow:0 18px 40px -20px rgba(15,23,42,.45);display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid #eef2f7}
.amt small{display:block;font-size:11.5px;color:#64748b;font-weight:700}
.amt .n{font-size:30px;font-weight:900;color:var(--a);line-height:1.4}.amt .n span{font-size:13px;color:#475569;margin-right:4px}
.amt .w{font-size:11.5px;color:#475569;margin-top:2px}
.dl{text-align:center;background:linear-gradient(160deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;color:#9a3412;border-radius:16px;padding:9px 14px}
.dl b{display:block;font-size:17px;font-weight:900}.dl small{color:#c2410c}
.bd{padding:20px 22px 24px}
.box{border:1px solid #eef2f7;border-radius:18px;padding:14px 16px;margin-top:14px;background:#fbfcfe}
.box h3{margin:0 0 10px;font-size:13.5px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:7px}
.box h3:before{content:"";width:6px;height:16px;border-radius:4px;background:linear-gradient(var(--a),var(--b))}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
.kv{background:#fff;border:1px solid #f1f5f9;border-radius:12px;padding:8px 11px}.kv small{display:block;font-size:10.5px;color:#94a3b8;font-weight:700}.kv b{font-size:13px;font-weight:700}
.accs{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px}
.acc{position:relative;border-radius:18px;padding:14px 16px 12px;color:#fff;background:linear-gradient(135deg,#0f172a,#334155);overflow:hidden;text-decoration:none;display:block;min-height:118px}
.acc:after{content:"";position:absolute;width:150px;height:150px;border-radius:50%;left:-50px;top:-60px;background:rgba(255,255,255,.08)}
.acc.card{background:linear-gradient(135deg,var(--a),var(--b))}.acc.card .acc-n{margin-top:30px}.acc.sheba{background:linear-gradient(135deg,#0f172a,#1e3a8a)}.acc.account{background:linear-gradient(135deg,#064e3b,#0f766e)}.acc.lnk{background:linear-gradient(135deg,#b45309,#db2777)}
.acc-h{display:flex;justify-content:space-between;gap:8px;font-size:11.5px;position:relative}.acc-t{font-weight:900;opacity:.95}.acc-b{background:rgba(255,255,255,.18);border-radius:99px;padding:1px 9px;font-weight:700}
.acc-n{position:relative;font-size:19px;font-weight:900;letter-spacing:1.5px;margin:18px 0 8px;font-family:Vazir,monospace;word-break:break-all;text-align:left}
.acc.sheba .acc-n{font-size:14.5px;letter-spacing:.6px}.acc.lnk .acc-n{font-size:13px;letter-spacing:0}
.acc-f{position:relative;display:flex;justify-content:space-between;align-items:center;gap:8px}.acc-o{font-size:11.5px;opacity:.9}
.cp{font:inherit;font-size:11px;font-weight:700;border:0;border-radius:9px;padding:4px 10px;background:rgba(255,255,255,.2);color:#fff;cursor:pointer}
.chip{position:absolute;right:16px;top:40px;width:34px;height:25px;border-radius:6px;background:linear-gradient(135deg,#fde68a,#d97706);opacity:.85}
.txt{font-size:12.5px;line-height:2.1;color:#334155}
.chs{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.ch{display:inline-flex;align-items:center;gap:7px;border-radius:12px;padding:6px 11px;font-size:12px;text-decoration:none;color:#0f172a;background:#fff;border:1px solid #e2e8f0}
.ch b{font-weight:900;color:var(--a)}.ch.whatsapp b{color:#16a34a}.ch.bale b{color:#0891b2}.ch.telegram b{color:#0284c7}.ch.eitaa b{color:#ea580c}
.warn{margin-top:14px;border-radius:14px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:12.5px;line-height:2}
.exp{margin-top:14px;display:flex;align-items:center;gap:12px;border-radius:18px;padding:12px 14px;background:linear-gradient(120deg,#f8fafc,#eef2ff);border:1px solid #e0e7ff}
.av{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--a),var(--b));color:#fff;font-weight:900;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ex-i small{display:block;font-size:11px;color:#64748b}.ex-i b{font-size:14px;font-weight:900}
.ex-c{margin-right:auto;font-weight:900;font-size:15px;color:var(--a);text-decoration:none;background:#fff;border-radius:12px;padding:6px 12px;border:1px solid #e0e7ff}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:7px 6px;text-align:center;border-bottom:1px solid #eef2f7}
th{font-size:11px;color:#64748b;font-weight:700;background:#f8fafc}tr.on td{background:#eef2ff;font-weight:700}.late{color:#dc2626;font-weight:700}
.note{margin-top:16px;font-size:12px;color:#475569;line-height:2;text-align:center}
.cut{margin:18px -22px 0;border-top:2px dashed #cbd5e1;position:relative}
.ft{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 22px 18px;font-size:11px;color:#94a3b8}
@media(max-width:560px){body{padding:10px 6px}.hd{padding:20px 18px 64px}.amt{grid-template-columns:1fr;margin:-48px 12px 0}.amt .n{font-size:25px}.bd{padding:16px 14px 20px}.meta{display:none}.lg{width:52px;height:52px}.acc-n{font-size:17px}}
@media print{@page{size:A4;margin:10mm}body{background:#fff;padding:0}.bar{display:none}.pg{box-shadow:none;border-radius:18px;max-width:none}.cp{display:none}}
</style></head><body>
<div class="bar"><button class="g" onclick="window.close()">بستن</button><button class="g" id="b-txt">کپیِ متن برای پیامک/واتساپ</button><button class="g" id="b-img" style="display:none">ذخیره‌ی عکس</button><button onclick="window.print()">چاپ / ذخیره‌ی PDF</button></div>
<div class="pg" id="slip">
<header class="hd"><div class="hd-r">' . ($logo !== '' ? '<div class="lg"><img src="' . $logo . '" alt=""></div>' : '')
    . '<div class="co"><b>' . $h($s['company']) . '</b>' . ($s['subtitle'] !== '' ? '<small>' . $h($s['subtitle']) . '</small>' : '') . '</div>'
    . '<div class="meta">شماره‌ی فیش: <b>' . $h($v['شماره_فیش']) . '</b><br>تاریخِ صدور: <b>' . $h($v['تاریخ_امروز']) . '</b></div></div>
<h1>' . $h($title) . '</h1></header>
<div class="amt"><div><small>مبلغِ قابلِ پرداخت' . ($r['with_over'] ? ' (همراه با اقساطِ معوقِ قبلی)' : '') . '</small><div class="n">' . $h($v['مبلغ_قابل_پرداخت']) . '<span>ریال</span></div>'
    . ($v['مبلغ_حروف'] !== '' ? '<div class="w">' . $h($v['مبلغ_حروف']) . '</div>' : '') . '</div><div class="dl"><small>مهلتِ پرداخت</small><b>' . $h($v['مهلت_پرداخت']) . '</b></div></div>
<div class="bd">
<section class="box"><h3>بیمه‌گذار و بیمه‌نامه</h3><div class="grid">
<div class="kv"><small>بیمه‌گذار</small><b>' . $h($v['نام_بیمه_گذار']) . '</b></div><div class="kv"><small>کد ملی</small><b>' . $h($v['کد_ملی'] ?: '—') . '</b></div>
<div class="kv"><small>شماره‌ی بیمه‌نامه</small><b><bdi>' . $h($v['شماره_بیمه_نامه']) . '</bdi></b></div>' . ($v['بیمه_شده'] !== '' ? '<div class="kv"><small>بیمه‌شده</small><b>' . $h($v['بیمه_شده']) . '</b></div>' : '')
    . ($v['طرح'] !== '' ? '<div class="kv"><small>طرح</small><b>' . $h($v['طرح']) . '</b></div>' : '') . ($v['روش_پرداخت_حق_بیمه'] !== '' ? '<div class="kv"><small>روشِ پرداختِ حق بیمه</small><b>' . $h($v['روش_پرداخت_حق_بیمه']) . '</b></div>' : '') . '
</div></section>
<section class="box"><h3>قسطِ ' . $h($v['شماره_قسط']) . '</h3><div class="grid">
<div class="kv"><small>سررسید</small><b>' . $h($v['سررسید']) . '</b>' . ($i['days'] > 0 && $i['rem'] > 0 ? ' <small style="display:inline;color:#dc2626">(' . life_fa($i['days']) . ' روز گذشته)</small>' : '') . '</div>
<div class="kv"><small>مبلغِ قسط</small><b>' . $h($v['مبلغ_قسط']) . ' ریال</b></div><div class="kv"><small>پرداخت‌شده</small><b>' . $h($v['پرداخت_شده']) . ' ریال</b></div>'
    . ($v['شناسه_واریز'] !== '' ? '<div class="kv"><small>شناسه‌ی واریز</small><b dir="ltr">' . $h($v['شناسه_واریز']) . '</b></div>' : '')
    . ($r['with_over'] ? '<div class="kv"><small>اقساطِ معوقِ قبلی</small><b style="color:#dc2626">' . $h($v['معوقات']) . ' ریال</b></div>' : '') . '
</div></section>' . $overBox
    . ($accHtml !== '' || $s['pay_text'] !== '' ? '<section class="box"><h3>نحوه‌ی پرداخت</h3>' . ($s['pay_text'] !== '' ? '<div class="txt" style="margin-bottom:10px">' . $nl($s['pay_text']) . '</div>' : '') . ($accHtml !== '' ? '<div class="accs">' . $accHtml . '</div>' : '') . '</section>' : '')
    . ($s['notify_text'] !== '' || $chHtml !== '' ? '<section class="box"><h3>بعد از پرداخت</h3>' . ($s['notify_text'] !== '' ? '<div class="txt">' . $nl($s['notify_text']) . '</div>' : '') . ($chHtml !== '' ? '<div class="chs">' . $chHtml . '</div>' : '') . '</section>' : '')
    . $expert
    . ($r['note'] !== '' ? '<section class="box"><h3>توضیح</h3><div class="txt">' . $nl($r['note']) . '</div></section>' : '')
    . $sched
    . ($s['note'] !== '' ? '<div class="note">' . $nl($s['note']) . '</div>' : '') . '
<div class="cut"></div></div>
<div class="ft"><span>صادرکننده: ' . $h($r['user']) . '</span><span>' . $h($s['company']) . ' · ' . $h($v['شماره_فیش']) . '</span></div>
</div>
<textarea id="slip-txt" style="position:fixed;top:0;right:0;width:1px;height:1px;opacity:0;pointer-events:none;border:0;padding:0" aria-hidden="true" tabindex="-1">' . $h($text) . '</textarea>
<script>
(function(){
  function copy(t,btn){var done=function(){if(btn){var o=btn.textContent;btn.textContent="کپی شد ✓";setTimeout(function(){btn.textContent=o},1500)}};
    if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done,function(){fb(t);done()})}else{fb(t);done()}}
  function fb(t){var a=document.createElement("textarea");a.style.cssText="position:fixed;top:0;right:0;opacity:0";a.value=t;document.body.appendChild(a);a.select();try{document.execCommand("copy")}catch(e){}a.remove()}
  document.querySelectorAll("[data-cp]").forEach(function(b){b.onclick=function(){copy(b.getAttribute("data-cp"),b)}});
  document.getElementById("b-txt").onclick=function(){copy(document.getElementById("slip-txt").value,this)};
  // ذخیره‌ی عکس (برای فرستادن در واتساپ/بله): اگر کتابخانه‌ی html2canvas در دسترس بود
  var s=document.createElement("script");s.src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js";
  s.onload=function(){var b=document.getElementById("b-img");b.style.display="";b.onclick=function(){b.disabled=true;document.querySelectorAll(".cp").forEach(function(x){x.style.visibility="hidden"});
    html2canvas(document.getElementById("slip"),{scale:2,backgroundColor:"#ffffff",useCORS:true}).then(function(c){document.querySelectorAll(".cp").forEach(function(x){x.style.visibility=""});
      var a=document.createElement("a");a.download=' . json_encode('فیش پرداخت - ' . $v['نام_بیمه_گذار'] . ' - قسط ' . $i['inst_no'] . '.png', JSON_UNESCAPED_UNICODE) . ';a.href=c.toDataURL("image/png");a.click();b.disabled=false})}};
  document.head.appendChild(s);
})();
</script></body></html>';
}

// ---------------------------------------------------------------------
//  Word: قالبِ پیش‌فرضِ سامانه (با لوگو) یا قالبِ دلخواه
// ---------------------------------------------------------------------
function life_slip_builtin_docx($pdo, $dest, $sample = false) {
    // $sample: فایلِ نمونه برای ساختنِ قالبِ دلخواه (همه‌ی کدها)؛ وگرنه بخش‌های خالیِ تنظیمات اصلاً نمی‌آیند
    if (!class_exists('ZipArchive')) return false;
    $s = life_slip_settings($pdo);
    [, $c1] = LIFE_SLIP_ACCENTS[$s['accent']];
    $col = ltrim($c1, '#');
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $run = function ($text, $o) use ($esc) {
        $sz = $o['sz'] ?? 22;
        return '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="' . ($o['font'] ?? 'B Nazanin') . '" w:hint="cs"/>' . (!empty($o['b']) ? '<w:b/><w:bCs/>' : '')
             . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:rtl/></w:rPr><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>';
    };
    $p = function ($text, $o = []) use ($run) {
        return '<w:p><w:pPr><w:bidi/><w:spacing w:before="' . ($o['before'] ?? 0) . '" w:after="' . ($o['after'] ?? 60) . '"/><w:jc w:val="' . ($o['jc'] ?? 'right') . '"/>'
             . (!empty($o['shade']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shade'] . '"/>' : '')
             . (!empty($o['box']) ? '<w:pBdr><w:top w:val="single" w:sz="8" w:color="' . $o['box'] . '"/><w:bottom w:val="single" w:sz="8" w:color="' . $o['box'] . '"/></w:pBdr>' : '') . '</w:pPr>'
             . ($text === '' ? '' : $run($text, $o)) . '</w:p>';
    };
    // لوگو (فقط PNG/JPG در Word)
    $logoXml = ''; $media = null;
    $lp = life_slip_logo_path($pdo, $s);
    if ($lp && in_array(strtolower(pathinfo($lp, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true) && ($sz = @getimagesize($lp))) {
        $hEmu = 640080; $wEmu = intval($hEmu * $sz[0] / max(1, $sz[1]));
        if ($wEmu > 2286000) { $wEmu = 2286000; $hEmu = intval($wEmu * $sz[1] / max(1, $sz[0])); }
        $media = ['path' => $lp, 'name' => 'logo.' . (strtolower(pathinfo($lp, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpeg')];
        $logoXml = '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:after="60"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $wEmu . '" cy="' . $hEmu . '"/><wp:docPr id="1" name="Logo"/>'
                 . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                 . '<pic:nvPicPr><pic:cNvPr id="1" name="logo"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                 . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $wEmu . '" cy="' . $hEmu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }
    $has = function ($k) use ($s, $sample) { return $sample || (is_array($s[$k]) ? count($s[$k]) > 0 : trim((string)$s[$k]) !== ''); };
    $hasAcc = $has('accounts') || $has('pay_text');
    $hasAfter = $has('notify_text') || $has('channels');
    $hasExp = $sample || $s['expert_name'] !== '' || $s['expert_mobile'] !== '';
    $body = $logoXml
          . $p('{{نام_مجموعه}}', ['jc' => 'center', 'sz' => 26, 'b' => true, 'font' => 'B Titr', 'color' => $col, 'after' => 20])
          . $p('{{عنوان}}', ['jc' => 'center', 'sz' => 32, 'b' => true, 'font' => 'B Titr', 'after' => 80])
          . $p('شماره‌ی فیش: {{شماره_فیش}}        تاریخ: {{تاریخ_امروز}}', ['jc' => 'center', 'sz' => 18, 'color' => '64748B', 'after' => 160])
          . $p('مبلغِ قابلِ پرداخت: {{مبلغ_قابل_پرداخت}} ریال', ['jc' => 'center', 'sz' => 30, 'b' => true, 'color' => $col, 'box' => $col, 'shade' => 'F1F5F9', 'before' => 60, 'after' => 0])
          . $p('{{مبلغ_حروف}}', ['jc' => 'center', 'sz' => 20, 'shade' => 'F1F5F9', 'after' => 0])
          . $p('مهلتِ پرداخت: {{مهلت_پرداخت}}', ['jc' => 'center', 'sz' => 24, 'b' => true, 'color' => 'C2410C', 'shade' => 'F1F5F9', 'box' => $col, 'after' => 200])
          . $p('بیمه‌گذار و بیمه‌نامه', ['sz' => 24, 'b' => true, 'font' => 'B Titr', 'color' => $col])
          . $p('بیمه‌گذار: {{نام_بیمه_گذار}}    کد ملی: {{کد_ملی}}    موبایل: {{موبایل}}', ['sz' => 22, 'b' => true])
          . $p('شماره‌ی بیمه‌نامه: {{شماره_بیمه_نامه}}    بیمه‌شده: {{بیمه_شده}}', ['sz' => 22])
          . $p('طرح: {{طرح}}    روشِ پرداختِ حق بیمه: {{روش_پرداخت_حق_بیمه}}', ['sz' => 21, 'after' => 160])
          . $p('قسطِ {{شماره_قسط}}', ['sz' => 24, 'b' => true, 'font' => 'B Titr', 'color' => $col])
          . $p('سررسید: {{سررسید}}    مبلغِ قسط: {{مبلغ_قسط}} ریال    پرداخت‌شده: {{پرداخت_شده}} ریال', ['sz' => 22])
          . $p('شناسه‌ی واریز: {{شناسه_واریز}}', ['sz' => 22, 'after' => 160])
          . ($hasAcc ? $p('نحوه‌ی پرداخت', ['sz' => 24, 'b' => true, 'font' => 'B Titr', 'color' => $col])
                     . ($has('pay_text') ? $p('{{نحوه_پرداخت}}', ['sz' => 22]) : '') . ($has('accounts') ? $p('{{حساب_ها}}', ['sz' => 23, 'b' => true]) : '') . $p('', ['after' => 100]) : '')
          . ($hasAfter ? $p('بعد از پرداخت', ['sz' => 24, 'b' => true, 'font' => 'B Titr', 'color' => $col])
                       . ($has('notify_text') ? $p('{{نحوه_اطلاع_رسانی}}', ['sz' => 22]) : '') . ($has('channels') ? $p('{{راه_های_ارتباط}}', ['sz' => 22]) : '') . $p('', ['after' => 100]) : '')
          . ($hasExp ? $p('{{سمت_کارشناس}}: {{کارشناس}}    {{موبایل_کارشناس}}', ['sz' => 22, 'b' => true, 'after' => 120]) : '')
          . $p('{{توضیح}}', ['sz' => 22, 'after' => 120])
          . $p('{{جدول_اقساط}}')
          . ($has('note') ? $p('{{متن_پایانی}}', ['jc' => 'center', 'sz' => 20, 'color' => '475569', 'before' => 200]) : '');
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:body>'
         . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="1000" w:bottom="900" w:left="1000" w:header="500" w:footer="500" w:gutter="0"/><w:bidi/></w:sectPr></w:body></w:document>';
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . ($media ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $media['name'] . '"/>' : '') . '</Relationships>');
    if ($media) $zip->addFile($media['path'], 'word/media/' . $media['name']);
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    return true;
}

function life_slip_docx($pdo, array $r, $tpl) {
    $dir = life_inst_dir(life_policy_row($pdo, $r['p']['id']), ['inst_no' => $r['inst']['inst_no'], 'due_j' => $r['inst']['due_j']]);
    if (!life_mkdir($dir)) return null;
    $dest = $dir . '/' . sanitize_folder_name('فیش پرداخت قسط ' . $r['inst']['inst_no'] . ' - ' . str_replace('/', '.', $r['today']) . ' - ' . date('His')) . '.docx';
    if ($tpl && $tpl['file_path'] && is_file(life_root() . '/' . $tpl['file_path'])) { if (!@copy(life_root() . '/' . $tpl['file_path'], $dest)) return null; }
    elseif (!life_slip_builtin_docx($pdo, $dest)) return null;
    $zip = new ZipArchive();
    if ($zip->open($dest) !== true) return null;
    $vars = life_slip_vars($r);
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $rows = []; $hi = -1;
    foreach ($r['rest'] as $k => $x) {
        if ($x['id'] === $r['inst']['id']) $hi = $k;
        $rows[] = [life_fa($x['inst_no']), life_fa($x['due_j']), life_money($x['amount']), life_money($x['rem']), $x['days'] > 0 ? life_fa($x['days']) . ' روز گذشته' : '—'];
    }
    $tbl = $r['s']['show_schedule'] && $rows ? life_docx_table(['قسط', 'سررسید', 'مبلغ (ریال)', 'مانده (ریال)', 'وضعیت'], $rows, [1000, 1700, 2300, 2300, 2000], $hi) : '';
    foreach (fin_docx_parts($zip) as $part) {
        $xml = fin_docx_heal_codes((string)$zip->getFromName($part));
        if ($part === 'word/document.xml' && strpos($xml, '{{جدول_اقساط}}') !== false) {
            $pat = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*\{\{جدول_اقساط\}\}(?:(?!<\/w:p>).)*<\/w:p>/s';
            $xml = preg_replace($pat, str_replace(['\\', '$'], ['\\\\', '\$'], $tbl), $xml, 1);
        }
        // کدهای چندخطی: هر خط یک پاراگراف با همان سبک؛ پاراگرافِ کدِ خالی حذف می‌شود
        foreach ($vars as $k => $val) {
            $code = '{{' . $k . '}}';
            if (strpos($xml, $code) === false) continue;
            $xml = preg_replace_callback('/<w:p\b[^>]*>(?:(?!<\/w:p>).)*' . preg_quote($code, '/') . '(?:(?!<\/w:p>).)*<\/w:p>/s', function ($m) use ($code, $val, $esc) {
                $plain = trim(preg_replace(['/\{\{[^{}]+\}\}/u', '/[\s:،\-·]+/u'], '', strip_tags($m[0])));
                if ((string)$val === '' && $plain === '') return '';
                $lines = preg_split('/\n/', (string)$val);
                if (count($lines) < 2) return str_replace($code, $esc($val), $m[0]);
                $o = '';
                foreach ($lines as $ln) $o .= str_replace($code, $esc($ln), $m[0]);
                return $o;
            }, $xml);
        }
        $xml = str_replace('{{جدول_اقساط}}', '', $xml);
        $zip->addFromString($part, $xml);
    }
    $zip->close();
    return $dest;
}
