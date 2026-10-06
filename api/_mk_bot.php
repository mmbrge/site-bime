<?php
// فایل: api/_mk_bot.php
// منوی «بازاریاب / کارشناس تماس» در ربات بله‌ی شرکت‌ها (company_bot_webhook.php):
//   🧾 ثبت فروش · 🎁 محاسبه نرخ پاداش · 💰 درآمد این ماه · 📊 گزارشات
// شناسایی با همان دکمه‌ی «ورود با شماره تلفن» (شماره‌ای که در «بازاریابی ← افراد» ثبت شده).
require_once __DIR__ . '/_mk.php';

const MKB_BTNS = ['🧾 ثبت فروش', '🎁 محاسبه نرخ پاداش', '💰 درآمد این ماه', '📊 گزارشات'];

function mkb_accounts_by_phone($pdo, $phone) {
    try { mk_ensure($pdo); } catch (Throwable $e) { return []; }
    return array_map(function ($p) { $p['role'] = $p['kind']; $p['username'] = null; $p['mobile_number'] = $p['mobile']; return ['type' => 'MARKETER', 'user' => $p]; }, mk_by_phone($pdo, $phone));
}
function mkb_chat_accounts($pdo, $chat) {
    try {
        mk_ensure($pdo);
        $st = $pdo->prepare("SELECT * FROM mk_people WHERE bale_chat_id = ? AND bot_linked_at IS NOT NULL AND active = 1 AND is_deleted = 0");
        $st->execute([$chat]);
        return array_map(function ($p) { $p['role'] = $p['kind']; $p['username'] = null; $p['mobile_number'] = $p['mobile']; return ['type' => 'MARKETER', 'user' => $p]; }, $st->fetchAll());
    } catch (Throwable $e) { return []; }
}
function is_marketer($acc) { return $acc && $acc['type'] === 'MARKETER'; }
function mkb_menu($multi = false) {
    $rows = [[['text' => '🧾 ثبت فروش'], ['text' => '🎁 محاسبه نرخ پاداش']], [['text' => '💰 درآمد این ماه'], ['text' => '📊 گزارشات']], [['text' => '👤 حساب من'], ['text' => '🚪 خروج از حساب']]];
    if ($multi) $rows[] = [['text' => '🔄 تغییر حساب']];
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}
function mkb_types_kb($prefix) {
    global $pdo;
    $rows = [];
    foreach (array_chunk(mk_types($pdo, true), 2) as $pair) $rows[] = array_map(function ($t) use ($prefix) { return ['text' => trim(($t['icon'] ?? '') . ' ' . $t['name']), 'callback_data' => $prefix . ':' . $t['id']]; }, $pair);
    return ['inline_keyboard' => $rows];
}
function mkb_fresh($pdo, $acc) { return mk_person($pdo, $acc['user']['id']) ?: $acc['user']; }

// منوی اصلیِ بازاریاب (دکمه‌های پایین)
function mkb_menu_action($chat, $acc, $text, $multi) {
    global $pdo;
    $p = mkb_fresh($pdo, $acc);
    if ($text === '🧾 ثبت فروش') {
        st_set($chat, 'MK_SALE', ['ctx' => auth_account_key($acc)]);
        say($chat, "🧾 ثبتِ فروش\nنوعِ بیمه‌نامه‌ای که فروختید را انتخاب کنید:", mkb_types_kb('mks'));
    } elseif ($text === '🎁 محاسبه نرخ پاداش') {
        st_set($chat, 'MK_CALC', ['ctx' => auth_account_key($acc)]);
        say($chat, "🎁 محاسبه‌ی پاداش و تخفیفِ مشتری\nمشتری چه بیمه‌نامه‌ای می‌خواهد بخرد؟", mkb_types_kb('mkc'));
    } elseif ($text === '💰 درآمد این ماه') {
        [$jy, $jm] = mk_now_jm();
        mkb_income($chat, $p, $jy, $jm);
    } elseif ($text === '📊 گزارشات') {
        [$jy, $jm] = mk_now_jm();
        $pjm = $jm > 1 ? $jm - 1 : 12; $pjy = $jm > 1 ? $jy : $jy - 1;
        say($chat, "📊 گزارشات\nگزارشِ PDF یا فهرستِ آخرین فروش‌ها:", ['inline_keyboard' => [
            [['text' => '📄 PDF ' . mk_month_label($jy, $jm), 'callback_data' => "mkr:m:$jy:$jm"]],
            [['text' => '📄 PDF ' . mk_month_label($pjy, $pjm), 'callback_data' => "mkr:m:$pjy:$pjm"]],
            [['text' => '📄 PDF کلِ سالِ ' . mk_fa($jy), 'callback_data' => "mkr:y:$jy"]],
            [['text' => '🧾 ۱۰ فروشِ آخر', 'callback_data' => 'mkr:last']],
        ]]);
    }
}
// درآمدِ یک ماه + دکمه‌های ماه‌های همین سال
function mkb_income($chat, array $p, $jy, $jm) {
    global $pdo;
    $inc = mk_income($pdo, $p, $jy, $jm);
    $d = $inc['data']; $R = $inc['rules'];
    $t = '💰 درآمدِ ' . $inc['label'] . ($R['level'] ? "\n" . $R['level']['emoji'] . ' سطح: ' . $R['level']['name'] : '') . "\n";
    if ($d['rows']) {
        $t .= "\nریز بر اساسِ نوعِ بیمه‌نامه:";
        foreach ($d['rows'] as $r) $t .= "\n• " . $r['type_name'] . "\n   " . mk_fa($r['n']) . ' فقره · حق‌بیمه ' . life_fa_money($r['premium']) . ' · حق بازاریابی ' . life_fa_money($r['fee']);
    } else $t .= "\nهنوز فروشی در این ماه ثبت نشده است.";
    $t .= "\n\n📌 جمعِ کل: " . mk_fa($d['n']) . " فقره\nکلِ حق‌بیمه: " . life_fa_money($d['premium']) . " ریال\nکلِ حق بازاریابی: " . life_fa_money($d['fee']) . ' ریال';
    $t .= "\n\n🧮 محاسبه‌ی درآمد:\nحقوقِ ثابت: " . life_fa_money($inc['base']) . "\nحق بازاریابی: " . life_fa_money($d['fee'])
        . ($inc['over'] ? "\nاضافه‌ی فروشِ بالای کف: " . life_fa_money($inc['over']) : '') . ($inc['volume'] ? "\nپاداشِ حجمِ فروش: " . life_fa_money($inc['volume']) : '')
        . ($inc['level_bonus'] ? "\nپاداشِ سطح: " . life_fa_money($inc['level_bonus']) : '') . "\n💵 جمعِ درآمد: " . life_fa_money($inc['total']) . ' ریال';
    if ($R['target']) $t .= "\n\n🎯 هدفِ ماهانه: " . life_fa_money($R['target']) . ' · ' . mk_fa($inc['target_pct']) . '٪' . ($inc['to_target'] ? ' (مانده ' . life_fa_money($inc['to_target']) . ')' : ' 🎉');
    $t .= "\n\n" . implode("\n", $inc['messages']);
    // دکمه‌ی ماه‌های همین سال (سال‌های قبل در پنل/بایگانی)
    [$cy, $cm] = mk_now_jm();
    $btn = [];
    $row = [];
    for ($m = 1; $m <= ($jy == $cy ? $cm : 12); $m++) {
        $row[] = ['text' => ($m === intval($jm) ? '• ' : '') . jalali_month_name($m), 'callback_data' => "mki:$cy:$m"];
        if (count($row) === 3) { $btn[] = $row; $row = []; }
    }
    if ($row) $btn[] = $row;
    $btn[] = [['text' => '📄 PDFِ همین ماه', 'callback_data' => "mkr:m:$jy:$jm"]];
    $lv = $R['level'];
    if ($lv && $lv['image_path'] && is_file(dirname(__DIR__) . '/' . $lv['image_path'])) {
        cbot_api($pdo, 'sendPhoto', ['chat_id' => $chat, 'photo' => new CURLFile(dirname(__DIR__) . '/' . $lv['image_path']), 'caption' => $lv['emoji'] . ' سطحِ ' . $lv['name']], true);
    }
    say($chat, $t, ['inline_keyboard' => $btn]);
}

// دکمه‌های شیشه‌ای
function mkb_callback($chat, $acc, $cmd, $a1, $a2) {
    global $pdo;
    $p = mkb_fresh($pdo, $acc);
    $s = st_get($chat);
    $t = $s['temp'];
    switch ($cmd) {
        case 'mks':   // ثبتِ فروش: نوع انتخاب شد
            $type = mk_type($pdo, intval($a1));
            if (!$type || !$type['active']) { say($chat, 'این نوع فعال نیست.'); return; }
            st_set($chat, 'MK_SALE', ['ctx' => auth_account_key($acc), 'type' => $type['id'], 'step' => 'premium']);
            say($chat, ($type['icon'] ? $type['icon'] . ' ' : '') . $type['name'] . "\n💵 حق‌بیمه‌ی این فروش را به ریال بنویسید (مثلاً ۱۲۵۰۰۰۰۰):" . ($type['min_premium'] ? "\nحداقل: " . life_fa_money($type['min_premium']) . ' ریال' : ''), ['keyboard' => [[['text' => '❌ انصراف']]], 'resize_keyboard' => true]);
            return;
        case 'mksn':  // بدونِ نامِ مشتری
            if ($s['state'] !== 'MK_SALE') return;
            $t['customer'] = ''; mkb_sale_confirm($chat, $p, $t); return;
        case 'mkok':
            if ($s['state'] !== 'MK_SALE' || ($t['step'] ?? '') !== 'confirm') return;
            $type = mk_type($pdo, $t['type']);
            $r = mk_sale_add($pdo, $p, $type, $t['premium'], ['customer_name' => $t['customer'] ?? '', 'source' => 'BOT']);
            st_set($chat, null, ['ctx' => auth_account_key($acc)]);
            if (isset($r['error'])) { say($chat, '❌ ' . $r['error'], mkb_menu()); return; }
            say($chat, mk_sale_message($pdo, $p, $type, $t['premium'], $r['fee'], $r['jy'], $r['jm']), ['inline_keyboard' => [[['text' => '↩️ لغوِ همین فروش', 'callback_data' => 'mkx:' . $r['id']], ['text' => '🧾 فروشِ دیگر', 'callback_data' => 'mknew']]]]);
            say($chat, 'از منوی زیر ادامه دهید:', mkb_menu());
            cbot_notify_staff($pdo, ['ADMIN'], '🧾 فروشِ تازه از ربات: ' . $p['full_name'] . ' · ' . $type['name'] . ' · ' . life_fa_money($t['premium']) . ' ریال');
            return;
        case 'mkno':
            st_set($chat, null, ['ctx' => auth_account_key($acc)]); say($chat, 'ثبتِ فروش لغو شد.', mkb_menu()); return;
        case 'mknew':
            st_set($chat, 'MK_SALE', ['ctx' => auth_account_key($acc)]); say($chat, 'نوعِ بیمه‌نامه را انتخاب کنید:', mkb_types_kb('mks')); return;
        case 'mkx':   // لغوِ فروشِ خودش تا ۳۰ دقیقه بعد از ثبت
            $st = $pdo->prepare("UPDATE mk_sales SET status = 'VOID', voided_at = NOW(), void_reason = 'لغو توسطِ خودِ بازاریاب در ربات' WHERE id = ? AND person_id = ? AND status = 'OK' AND source = 'BOT' AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            $st->execute([intval($a1), $p['id']]);
            say($chat, $st->rowCount() ? '↩️ فروش لغو شد.' : 'این فروش دیگر قابلِ لغو نیست (فقط تا ۳۰ دقیقه بعد از ثبت)؛ با مدیر هماهنگ کنید.');
            return;
        case 'mkc':   // محاسبه‌ی پاداش: نوع
            $type = mk_type($pdo, intval($a1));
            if (!$type) return;
            st_set($chat, 'MK_CALC', ['ctx' => auth_account_key($acc), 'type' => $type['id'], 'step' => 'premium']);
            say($chat, ($type['icon'] ? $type['icon'] . ' ' : '') . $type['name'] . "\n💵 حق‌بیمه را به ریال بنویسید:", ['keyboard' => [[['text' => '❌ انصراف']]], 'resize_keyboard' => true]);
            return;
        case 'mkcp':
            if ($s['state'] !== 'MK_CALC') return;
            $t['pay'] = array_key_exists($a1, MK_PAY) ? $a1 : 'any'; $t['step'] = 'cust'; st_set($chat, 'MK_CALC', $t);
            say($chat, '👤 مشتریِ جدید است یا تمدید / مشتریِ قبلی؟', ['inline_keyboard' => [[['text' => 'مشتریِ جدید', 'callback_data' => 'mkcc:new'], ['text' => 'تمدید / قبلی', 'callback_data' => 'mkcc:renewal']], [['text' => 'فرقی نمی‌کند', 'callback_data' => 'mkcc:any']]]]);
            return;
        case 'mkcc':
            if ($s['state'] !== 'MK_CALC') return;
            $t['cust'] = array_key_exists($a1, MK_CUST) ? $a1 : 'any'; $t['step'] = 'count'; st_set($chat, 'MK_CALC', $t);
            say($chat, '🔢 چند بیمه‌نامه / چند نفر همزمان؟', ['inline_keyboard' => [[['text' => '۱', 'callback_data' => 'mkcn:1'], ['text' => '۲', 'callback_data' => 'mkcn:2'], ['text' => '۳', 'callback_data' => 'mkcn:3']],
                [['text' => '۴', 'callback_data' => 'mkcn:4'], ['text' => '۵ تا ۹', 'callback_data' => 'mkcn:5'], ['text' => '۱۰ و بیشتر', 'callback_data' => 'mkcn:10']]]]);
            return;
        case 'mkcn':
            if ($s['state'] !== 'MK_CALC') return;
            $type = mk_type($pdo, $t['type']);
            $fee = mk_fee($type, $t['premium']);
            $c = mk_reward_calc($pdo, $type['id'], $t['premium'], $t['pay'] ?? 'any', $t['cust'] ?? 'any', intval($a1), $fee['commission']);
            st_set($chat, null, ['ctx' => auth_account_key($acc)]);
            say($chat, mk_reward_text($type, $t['premium'], $c, $t['pay'] ?? 'any', $t['cust'] ?? 'any', intval($a1)) . "\n\n💰 حق بازاریابیِ شما از این فروش (پیش از تخفیف): " . life_fa_money($fee['fee']) . ' ریال',
                ['inline_keyboard' => [[['text' => '🧾 ثبتِ همین فروش', 'callback_data' => 'mkq:' . $type['id'] . ':' . intval($c['final'] ?: $t['premium'])], ['text' => '🎁 محاسبه‌ی دیگر', 'callback_data' => 'mkcalc']]]]);
            say($chat, 'از منوی زیر ادامه دهید:', mkb_menu());
            return;
        case 'mkcalc':
            st_set($chat, 'MK_CALC', ['ctx' => auth_account_key($acc)]); say($chat, 'نوعِ بیمه‌نامه را انتخاب کنید:', mkb_types_kb('mkc')); return;
        case 'mkq':   // ثبتِ فروش از نتیجه‌ی محاسبه
            $t = ['ctx' => auth_account_key($acc), 'type' => intval($a1), 'premium' => intval($a2), 'step' => 'customer'];
            st_set($chat, 'MK_SALE', $t);
            say($chat, '👤 نامِ مشتری را بنویسید (اختیاری):', ['inline_keyboard' => [[['text' => 'بدونِ نام، ادامه', 'callback_data' => 'mksn']]]]);
            return;
        case 'mki':
            mkb_income($chat, $p, intval($a1), intval($a2)); return;
        case 'mkr':
            if ($a1 === 'last') {
                $st = $pdo->prepare("SELECT * FROM mk_sales WHERE person_id = ? ORDER BY id DESC LIMIT 10");
                $st->execute([$p['id']]);
                $rows = $st->fetchAll();
                if (!$rows) { say($chat, 'هنوز فروشی ثبت نکرده‌اید.'); return; }
                $x = '🧾 ۱۰ فروشِ آخرِ شما:';
                foreach ($rows as $r) $x .= "\n\n" . ($r['status'] === 'OK' ? '✅ ' : '❌ ') . mk_fa($r['sale_j']) . ' · ' . $r['type_name'] . "\n   حق‌بیمه " . life_fa_money($r['premium']) . ' · حق بازاریابی ' . life_fa_money($r['fee_amount']) . ($r['customer_name'] ? ' · ' . $r['customer_name'] : '') . ($r['status'] !== 'OK' ? ' (باطل)' : '');
                say($chat, $x);
                return;
            }
            say($chat, '⏳ در حالِ ساختِ گزارش…');
            $parts = explode(':', $a2 ?? '');
            $jy = intval($parts[0] ?? 0); $jm = $a1 === 'm' ? intval($parts[1] ?? 0) : null;
            try { $file = mk_pdf($pdo, $p['id'], $jy, $jm); } catch (Throwable $e) { error_log('[mk_pdf] ' . $e->getMessage()); say($chat, 'ساختِ گزارش ممکن نشد.'); return; }
            cbot_send_file($pdo, $chat, $file, '📊 کارنامه‌ی فروشِ ' . ($jm ? mk_month_label($jy, $jm) : 'سالِ ' . mk_fa($jy)), basename($file));
            return;
    }
}
function mkb_sale_confirm($chat, array $p, array $t) {
    global $pdo;
    $type = mk_type($pdo, $t['type']);
    $f = mk_fee($type, $t['premium']);
    $t['step'] = 'confirm';
    st_set($chat, 'MK_SALE', $t);
    say($chat, "📝 خلاصه‌ی فروش:\n" . ($type['icon'] ? $type['icon'] . ' ' : '') . $type['name'] . "\nحق‌بیمه: " . life_fa_money($t['premium']) . ' ریال' . (($t['customer'] ?? '') !== '' ? "\nمشتری: " . $t['customer'] : '')
        . "\nکارمزد (" . mk_fa($type['commission_pct']) . '٪): ' . life_fa_money($f['commission']) . "\n💰 حق بازاریابیِ شما: " . life_fa_money($f['fee']) . ' ریال' . ($f['share'] ? ' (' . mk_fa($f['share']) . '٪ از کارمزد)' : '')
        . ($f['warn'] ? "\n⚠️ " . $f['warn'] : '') . "\n\nثبت شود؟", ['inline_keyboard' => [[['text' => '✅ ثبتِ نهایی', 'callback_data' => 'mkok'], ['text' => '❌ انصراف', 'callback_data' => 'mkno']]]]);
}
// متن‌های تایپ‌شده در حالت‌های بازاریاب؛ true یعنی رسیدگی شد
function mkb_state($chat, $acc, $state, array $t, $text) {
    global $pdo;
    $p = mkb_fresh($pdo, $acc);
    if ($state === 'MK_SALE') {
        if (($t['step'] ?? '') === 'premium') {
            $v = money_to_int($text);
            if ($v <= 0) { say($chat, 'حق‌بیمه را فقط با عدد (ریال) بنویسید:'); return true; }
            $t['premium'] = $v; $t['step'] = 'customer'; st_set($chat, 'MK_SALE', $t);
            $need = !empty(mk_settings($pdo)['sale_needs_customer']);
            say($chat, '👤 نامِ مشتری را بنویسید' . ($need ? ':' : ' (اختیاری):'), $need ? null : ['inline_keyboard' => [[['text' => 'بدونِ نام، ادامه', 'callback_data' => 'mksn']]]]);
            return true;
        }
        if (($t['step'] ?? '') === 'customer' && $text !== '') { $t['customer'] = mb_substr($text, 0, 120); mkb_sale_confirm($chat, $p, $t); return true; }
        if (($t['step'] ?? '') === 'confirm') { say($chat, 'برای ثبت، «✅ ثبتِ نهایی» را بزنید.'); return true; }
        say($chat, 'اول نوعِ بیمه‌نامه را از دکمه‌ها انتخاب کنید.', mkb_types_kb('mks'));
        return true;
    }
    if ($state === 'MK_CALC') {
        if (($t['step'] ?? '') === 'premium') {
            $v = money_to_int($text);
            if ($v <= 0) { say($chat, 'حق‌بیمه را فقط با عدد (ریال) بنویسید:'); return true; }
            $t['premium'] = $v; $t['step'] = 'pay'; st_set($chat, 'MK_CALC', $t);
            say($chat, '💳 پرداخت چطور است؟', ['inline_keyboard' => [[['text' => 'نقدی', 'callback_data' => 'mkcp:cash'], ['text' => 'اقساطی', 'callback_data' => 'mkcp:inst']], [['text' => 'فرقی نمی‌کند', 'callback_data' => 'mkcp:any']]]]);
            return true;
        }
        say($chat, 'از دکمه‌های پیامِ قبل انتخاب کنید یا «❌ انصراف» را بزنید.');
        return true;
    }
    return false;
}
