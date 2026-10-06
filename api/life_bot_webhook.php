<?php
// فایل: api/life_bot_webhook.php
// ربات بله‌ی «بیمه عمر». توکن و وب‌هوک از «بیمه عمر ← تنظیمات ← ربات بله» تنظیم می‌شود.
//  - همکار (کاربرِ پنل با دسترسیِ بیمه عمر، شناسایی با شماره): معوق‌ها، پیگیری‌های امروز و پیگیری‌های من، جستجو، خلاصه،
//    کارتِ بیمه‌نامه با اقساط، ثبتِ تماس (نتیجه، شرح، پیگیریِ بعدی)، ثبتِ پرداخت با عکسِ فیش، رسید، پیگیری کردن؛
//    هر کار فقط اگر همان دسترسی را در سایت داشته باشد و به نامِ خودش ثبت می‌شود.
//  - مشتری (بیمه‌گذار): شماره‌ی خودش + کد ملی؛ اگر شماره‌ای ثبت نشده بود همین شماره ثبت می‌شود و اگر بود باید یکی باشد.
//    اقساط به‌شکلِ دکمه‌های شیشه‌ای با وضعیتِ پرداخت، متنِ زیرِ رسید زیرِ پیامِ اقساط، رسیدِ قسط‌های پرداخت‌شده و یادآوری‌ها.
require '../config/db.php';
require_once __DIR__ . '/_life_bot.php';
require_once __DIR__ . '/finance_core.php';

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;
lbot_ensure($pdo);
if (lbot_token($pdo) === '') exit;

// ---------------------------------------------------------------------
//  کمکی‌ها
// ---------------------------------------------------------------------
function L_kb($rows) { return ['keyboard' => $rows, 'resize_keyboard' => true]; }
function L_ikb($rows) { return ['inline_keyboard' => $rows]; }
function L_say($chat, $text, $markup = null) { global $pdo; return lbot_send($pdo, $chat, $text, $markup); }
function L_fa($s) { return life_fa($s); }
function L_contact_kb() { return L_kb([[['text' => '📱 ارسالِ شماره‌ی من', 'request_contact' => true]]]); }
function L_st($chat) {
    global $pdo;
    $st = $pdo->prepare("SELECT state, temp FROM life_bot_state WHERE chat_id = ?");
    $st->execute([$chat]);
    $r = $st->fetch();
    return ['state' => $r['state'] ?? null, 'temp' => json_decode($r['temp'] ?? '', true) ?: []];
}
function L_set($chat, $state, $temp = []) {
    global $pdo;
    $pdo->prepare("INSERT INTO life_bot_state (chat_id, state, temp, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE state = VALUES(state), temp = VALUES(temp), updated_at = NOW()")
        ->execute([$chat, $state, json_encode($temp, JSON_UNESCAPED_UNICODE)]);
}
function L_reset($chat) { L_set($chat, null, []); }
function L_staff_menu(array $perms) {
    $r = [];
    $a = [];
    if (lbot_can($perms, 'life-policies:view')) { $a[] = ['text' => '🔴 معوق‌ها']; $a[] = ['text' => '📅 پیگیری‌های امروز']; }
    if ($a) $r[] = $a;
    $b = [];
    if (lbot_can($perms, 'life-policies:view')) { $b[] = ['text' => '🔎 جستجو']; $b[] = ['text' => '👥 پیگیری‌های من']; }
    if ($b) $r[] = $b;
    $c = [];
    if (lbot_can($perms, 'life-dash:view')) $c[] = ['text' => '📊 خلاصه'];
    $c[] = ['text' => '🔔 خلاصه‌ی روزانه'];
    $r[] = $c;
    $r[] = [['text' => '🚪 خروج']];
    return L_kb($r);
}
function L_customer_menu($link) {
    return L_kb([[['text' => '📋 اقساطِ من']], [['text' => !empty($link['remind']) ? '🔕 خاموش کردنِ یادآوری' : '🔔 روشن کردنِ یادآوری'], ['text' => '☎️ تماس با ما']], [['text' => '🚪 خروج']]]);
}
function L_inst_btn(array $i) {
    return LBOT_ST_ICON[$i['status']] . ' قسط ' . L_fa($i['inst_no']) . ' · ' . L_fa($i['due_j']) . ' · ' . life_money($i['amount']) . ($i['status'] === 'PARTIAL' ? ' (مانده ' . life_money($i['rem']) . ')' : '');
}

// ---------------------------------------------------------------------
//  مشتری
// ---------------------------------------------------------------------
function C_policies($link) { global $pdo; return lbot_customer_policies($pdo, $link['nid'], $link['phone']); }
function C_list($chat, $link) {
    $ps = C_policies($link);
    if (!$ps) { L_say($chat, 'بیمه‌نامه‌ای برای شما پیدا نشد؛ اگر شماره‌ی تماس‌تان عوض شده با ما تماس بگیرید.'); return; }
    if (count($ps) === 1) { C_policy($chat, $link, intval($ps[0]['id'])); return; }
    L_say($chat, '📄 بیمه‌نامه‌های عمرِ شما (برای دیدنِ اقساط روی هر کدام بزنید):', L_ikb(array_map(function ($p) {
        return [['text' => L_fa($p['policy_no']) . ' · ' . ($p['pay_method'] ?: '') . ' · صدور ' . L_fa($p['issue_j']), 'callback_data' => 'cp:' . $p['id']]];
    }, $ps)));
}
function C_own($link, $pid) { foreach (C_policies($link) as $p) if (intval($p['id']) === intval($pid)) return $p; return null; }
function C_policy($chat, $link, $pid) {
    global $pdo;
    if (!C_own($link, $pid)) { L_say($chat, 'این بیمه‌نامه پیدا نشد.'); return; }
    $d = life_detail($pdo, $pid);
    $p = $d['policy']; $s = $d['sum'];
    $t = "📄 بیمه‌نامه‌ی عمر " . L_fa($p['policy_no']) . "\n👤 " . $p['holder_name'] . "\n🗓 صدور: " . L_fa($p['issue_j']) . ' · ' . ($p['pay_method'] ?: '')
       . "\n💳 شناسه‌ی واریز: " . L_fa($p['pay_id'] ?: '—')
       . "\n\nجمعِ اقساط: " . life_money($s['amount']) . " ریال\n✅ پرداخت‌شده: " . life_money($s['paid']) . " ریال\n⏳ مانده: " . life_money($s['rem']) . ' ریال'
       . ($s['overdue'] ? "\n🔴 معوق: " . life_money($s['overdue']) . ' ریال (' . L_fa($s['overdue_count']) . ' قسط)' : '')
       . "\n\n✅ پرداخت‌شده · 🟡 بخشی · 🔴 معوق · ⏳ سررسید نشده\nبرای جزئیاتِ هر قسط روی آن بزنید:";
    $foot = lbot_footer($pdo);
    if ($foot !== '') $t .= "\n\n" . $foot;
    $btn = array_map(function ($i) { return [['text' => L_inst_btn($i), 'callback_data' => 'ci:' . $i['id']]]; }, array_slice($d['insts'], -40));
    L_say($chat, $t, L_ikb($btn));
}
function C_inst($chat, $link, $iid) {
    global $pdo;
    $st = $pdo->prepare("SELECT policy_id FROM life_installments WHERE id = ?");
    $st->execute([$iid]);
    $pid = intval($st->fetchColumn());
    if (!$pid || !C_own($link, $pid)) { L_say($chat, 'این قسط پیدا نشد.'); return; }
    $d = life_detail($pdo, $pid);
    foreach ($d['insts'] as $i) if ($i['id'] === intval($iid)) break;
    $t = LBOT_ST_ICON[$i['status']] . ' قسطِ ' . L_fa($i['inst_no']) . ' · ' . $i['status_fa'] . "\nسررسید: " . L_fa($i['due_j']) . "\nمبلغ: " . life_money($i['amount']) . " ریال\nپرداخت‌شده: "
       . life_money($i['eff_paid']) . " ریال\nمانده: " . life_money($i['rem']) . ' ریال'
       . ($i['days'] > 0 && $i['rem'] > 0 ? "\n⚠️ " . L_fa($i['days']) . ' روز از سررسید گذشته است.' : '')
       . "\nشناسه‌ی واریز: " . L_fa($i['pay_id'] ?: $d['policy']['pay_id'] ?: '—');
    foreach ($i['payments'] as $py) if (!$py['voided']) $t .= "\n💰 " . L_fa($py['paid_j']) . ' · ' . life_money($py['amount']) . ' ریال · ' . $py['method'] . ($py['ref_no'] ? ' · پیگیری ' . L_fa($py['ref_no']) : '');
    $btn = [];
    if ($i['eff_paid'] > 0) $btn[] = [['text' => '📄 دریافتِ رسید', 'callback_data' => 'cr:' . $i['id']]];
    $btn[] = [['text' => '🔙 بازگشت به اقساط', 'callback_data' => 'cp:' . $pid]];
    L_say($chat, $t, L_ikb($btn));
}
function L_send_receipt($chat, $iid, $payId, $uid) {
    global $pdo;
    $r = life_receipt_data($pdo, $iid, $payId, $uid);
    if (!$r) { L_say($chat, 'قسط پیدا نشد.'); return; }
    $tpl = life_template_row($pdo, 0);
    $docx = $tpl ? life_receipt_docx($pdo, $r, $tpl) : null;
    if (!$docx) { L_say($chat, 'ساختِ رسید ممکن نشد.'); return; }
    $file = $docx;
    if (fin_pdf_converter_available()) { $pdf = preg_replace('/\.docx$/', '.pdf', $docx); if (fin_docx_to_pdf($docx, dirname($docx), $pdf, life_root()) && is_file($pdf)) $file = $pdf; }
    lbot_send_file($pdo, $chat, $file, 'رسیدِ قسطِ ' . L_fa($r['inst']['inst_no']) . ' بیمه‌نامه‌ی ' . L_fa($r['p']['policy_no']), basename($file));
}

// ---------------------------------------------------------------------
//  همکار
// ---------------------------------------------------------------------
function S_policies($chat, $title, array $f, $page, $cbPrefix) {
    global $pdo;
    $d = life_list($pdo, $f + ['sort' => $f['sort'] ?? 'overdue'], $page + 1, 8);
    if (!$d['rows']) { L_say($chat, $page ? 'مورد دیگری نیست.' : $title . "\nموردی نیست. 🎉"); return; }
    $btn = array_map(function ($r) {
        return [['text' => ($r['overdue_count'] ? '🔴 ' : ($r['next_follow'] ? '📅 ' : '🟢 ')) . $r['holder_name'] . ' · ' . ($r['overdue_count'] ? life_money($r['overdue_amount']) . ' معوق' : L_fa($r['policy_no'])), 'callback_data' => 'p:' . $r['id']]];
    }, $d['rows']);
    if ($d['total'] > ($page + 1) * 8) $btn[] = [['text' => 'بیشتر ◀️ (' . L_fa($d['total'] - ($page + 1) * 8) . ')', 'callback_data' => $cbPrefix . ':' . ($page + 1)]];
    L_say($chat, $title . ' (' . L_fa($d['total']) . ")\nمعوقِ کل: " . life_money($d['sum']['overdue']) . ' ریال', L_ikb($btn));
}
function S_policy($chat, $uid, array $perms, $pid) {
    global $pdo;
    $d = life_detail($pdo, $pid);
    if (!$d) { L_say($chat, 'بیمه‌نامه پیدا نشد.'); return; }
    $p = $d['policy']; $s = $d['sum'];
    $phones = implode('، ', array_map(function ($x) { return L_fa($x['phone']) . ($x['label'] ? ' (' . $x['label'] . ')' : ''); }, $p['phones_list'])) ?: '— ثبت نشده';
    $last = null;
    foreach ($d['notes'] as $n) if ($n['kind'] === 'call') { $last = $n; break; }
    $t = "📄 " . $p['holder_name'] . "\nبیمه‌نامه: " . L_fa($p['policy_no']) . "\nکد ملی: " . L_fa($p['holder_nid'] ?: '—') . "\n📞 " . $phones
       . "\n" . ($p['pay_method'] ?: '') . ' · صدور ' . L_fa($p['issue_j']) . ' · حق‌بیمه‌ی یک سال ' . life_money($p['annual_premium'])
       . "\n\nپرداختی: " . life_money($s['paid']) . ' · مانده: ' . life_money($s['rem']) . ($s['overdue'] ? "\n🔴 معوق: " . life_money($s['overdue']) . ' (' . L_fa($s['overdue_count']) . ' قسط)' : '')
       . "\n👥 پیگیری‌کنندگان: " . (implode('، ', array_column($p['followers'], 'name')) ?: '—')
       . ($last ? "\n☎️ آخرین تماس: " . L_fa($last['at_j'] . ' ' . $last['at_t']) . ' · ' . ($last['result'] ?: '') . ' · ' . $last['by'] . ($last['text'] ? "\n   «" . mb_substr($last['text'], 0, 200) . '»' : '') : '');
    $btn = array_map(function ($i) { return [['text' => L_inst_btn($i), 'callback_data' => 'si:' . $i['id']]]; }, array_slice($d['insts'], -24));
    $row = [];
    if (lbot_can($perms, 'life-calls:create')) $row[] = ['text' => '📞 ثبتِ تماس / یادداشت', 'callback_data' => 'cn:' . $pid];
    if (lbot_can($perms, 'life-calls:create|life-pay:create|life-policies:edit'))
        $row[] = ['text' => in_array($uid, array_column($p['followers'], 'id'), true) ? '👤 دیگر پیگیری نمی‌کنم' : '👤 پیگیری می‌کنم', 'callback_data' => 'fo:' . $pid];
    if ($row) $btn[] = $row;
    $row = [];
    if (lbot_can($perms, 'life-calls:view')) $row[] = ['text' => '📝 یادداشت‌ها', 'callback_data' => 'nl:' . $pid];
    if (lbot_can($perms, 'life-calls:create') && $s['rem'] > 0) $row[] = ['text' => '🔔 یادآوری به بیمه‌گذار', 'callback_data' => 'rm:' . $pid];
    if ($row) $btn[] = $row;
    L_say($chat, $t, L_ikb($btn));
}
function S_inst($chat, array $perms, $iid) {
    global $pdo;
    $st = $pdo->prepare("SELECT policy_id FROM life_installments WHERE id = ?");
    $st->execute([$iid]);
    $pid = intval($st->fetchColumn());
    if (!$pid) { L_say($chat, 'قسط پیدا نشد.'); return; }
    $d = life_detail($pdo, $pid);
    foreach ($d['insts'] as $i) if ($i['id'] === intval($iid)) break;
    $t = LBOT_ST_ICON[$i['status']] . ' قسطِ ' . L_fa($i['inst_no']) . ' · ' . $d['policy']['holder_name'] . "\nسررسید: " . L_fa($i['due_j']) . ($i['days'] > 0 && $i['rem'] > 0 ? ' (' . L_fa($i['days']) . ' روز گذشته)' : '')
       . "\nمبلغ: " . life_money($i['amount']) . ' · پرداختی: ' . life_money($i['eff_paid']) . ' · مانده: ' . life_money($i['rem']);
    foreach ($i['payments'] as $py) $t .= "\n💰 " . L_fa($py['paid_j']) . ' · ' . life_money($py['amount']) . ' · ' . $py['method'] . ($py['ref_no'] ? ' · ' . L_fa($py['ref_no']) : '') . ' · ثبت: ' . $py['by'] . ($py['voided'] ? ' (باطل)' : '');
    if ($i['cleared_note']) $t .= "\n✔️ " . L_fa($i['cleared_note']);
    $btn = [];
    if (lbot_can($perms, 'life-pay:create') && $i['rem'] > 0) $btn[] = [['text' => '💰 ثبتِ پرداخت', 'callback_data' => 'pa:' . $i['id']]];
    if (lbot_can($perms, 'life-pay:view') && $i['eff_paid'] > 0) $btn[] = [['text' => '📄 رسید', 'callback_data' => 'sr:' . $i['id']]];
    $btn[] = [['text' => '🔙 بیمه‌نامه', 'callback_data' => 'p:' . $pid]];
    L_say($chat, $t, L_ikb($btn));
}
function S_notes($chat, $pid) {
    global $pdo;
    $d = life_detail($pdo, $pid);
    if (!$d) return;
    $t = '📝 یادداشت‌ها و تماس‌های ' . $d['policy']['holder_name'];
    $n = 0;
    foreach ($d['notes'] as $x) {
        if ($x['kind'] === 'sys') continue;
        $t .= "\n\n" . ($x['kind'] === 'call' ? '☎️ ' : '📝 ') . L_fa($x['at_j'] . ' ' . $x['at_t']) . ' · ' . $x['by'] . ($x['result'] ? ' · ' . $x['result'] : '') . ($x['phone'] ? ' · ' . L_fa($x['phone']) : '')
            . ($x['text'] ? "\n" . mb_substr($x['text'], 0, 400) : '') . ($x['next_j'] ? "\n🔔 پیگیریِ بعدی: " . L_fa($x['next_j']) : '');
        if (++$n >= 10) break;
    }
    if (!$n) $t .= "\nهنوز تماسی ثبت نشده است.";
    L_say($chat, $t, L_ikb([[['text' => '🔙 بیمه‌نامه', 'callback_data' => 'p:' . $pid]]]));
}
function S_summary($chat) {
    global $pdo;
    $s = life_stats($pdo, []);
    $I = $s['insts']; $P = $s['policies'];
    L_say($chat, "📊 خلاصه‌ی بیمه عمر (" . L_fa($s['today_j']) . ")\n\nبیمه‌نامه‌ها: " . L_fa($P['n']) . ' · معوق‌دار: ' . L_fa($P['overdue'])
        . "\nجمعِ اقساط: " . life_money($I['amount']) . "\n✅ وصول‌شده: " . life_money($I['paid']) . ' (' . L_fa($I['rate']) . "٪)\n⏳ مانده: " . life_money($I['rem'])
        . "\n🔴 معوق: " . life_money($I['over_amount']) . ' · ' . L_fa($I['n_over']) . " قسط\n📅 سررسیدِ ۳۰ روزِ آینده: " . life_money($I['soon_amount']) . ' · ' . L_fa($I['n_soon']) . ' قسط'
        . "\n☎️ تماس‌های این ماه: " . L_fa($s['calls_month']) . ' · پیگیری‌های سررسیده: ' . L_fa($s['followups_total']));
}
// ثبتِ تماس: نتیجه ← شرح ← پیگیریِ بعدی
function S_call_save($chat, $uid, array $t, $nextDays) {
    global $pdo;
    $p = life_policy_row($pdo, $t['pid']);
    if (!$p) return;
    $nextJ = $nextG = null;
    if ($nextDays > 0) { $nextG = date('Y-m-d', strtotime("+$nextDays days")); $nextJ = life_g2j($nextG); }
    $kind = ($t['result'] ?? '') === '' ? 'note' : 'call';
    $pdo->prepare("INSERT INTO life_notes (policy_id, kind, at, phone, result, next_j, next_g, text, created_by) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)")
        ->execute([$p['id'], $kind, $kind === 'call' ? ($p['holder_mobile'] ?: null) : null, ($t['result'] ?? '') ?: null, $nextJ, $nextG, ($t['text'] ?? '') ?: null, $uid]);
    $pdo->prepare("UPDATE life_policies SET updated_at = NOW() WHERE id = ?")->execute([$p['id']]);
    life_follow($pdo, $p['id'], $uid);
    life_write_archive($pdo, $p['id']);
    L_reset($chat);
    L_say($chat, '✅ ' . ($kind === 'call' ? 'تماس' : 'یادداشت') . ' به نامِ شما ثبت شد' . ($nextJ ? ' · پیگیریِ بعدی: ' . L_fa($nextJ) : '') . '.', L_ikb([[['text' => '🔙 بیمه‌نامه', 'callback_data' => 'p:' . $p['id']]]]));
}
function S_pay_ask($chat, $t, $step) {
    global $pdo;
    $t['step'] = $step;
    L_set($chat, 'PAY', $t);
    if ($step === 'amount') L_say($chat, '💰 مبلغِ پرداخت (ریال) را بنویسید:', L_ikb([[['text' => 'کلِ مانده: ' . life_money($t['rem']), 'callback_data' => 'pv:full']]]));
    elseif ($step === 'date') L_say($chat, '📅 تاریخِ پرداخت؟ (یا بنویسید مثلاً ۱۴۰۵/۰۷/۱۰)', L_ikb([[['text' => 'امروز', 'callback_data' => 'pd:0'], ['text' => 'دیروز', 'callback_data' => 'pd:1'], ['text' => 'پریروز', 'callback_data' => 'pd:2']]]));
    elseif ($step === 'method') L_say($chat, '🏦 روشِ پرداخت:', L_ikb(array_chunk(array_map(function ($m, $k) { return ['text' => $m, 'callback_data' => 'pm:' . $k]; }, LIFE_PAY_METHODS, array_keys(LIFE_PAY_METHODS)), 2)));
    elseif ($step === 'ref') L_say($chat, '🔢 شماره‌ی پیگیری / مرجع را بنویسید:', L_ikb([[['text' => 'ندارد', 'callback_data' => 'pr:-']]]));
    elseif ($step === 'file') L_say($chat, '🧾 عکس یا PDFِ فیش را بفرستید:', L_ikb([[['text' => 'بدونِ فیش، ثبت کن', 'callback_data' => 'pf:-']]]));
}
function S_pay_save($chat, $uid, array $t, $message = null) {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE id = ?");
    $st->execute([$t['iid']]);
    $i = $st->fetch();
    if (!$i) { L_reset($chat); return; }
    $file = null;
    if ($message) {
        $dir = life_inst_dir(life_policy_row($pdo, $i['policy_id']), $i);
        $r = lbot_download($pdo, $message, $dir, 'فیش پرداخت - ' . str_replace('/', '.', $t['paid_j']) . ' - ' . number_format($t['amount']));
        if (isset($r['error'])) { L_say($chat, '❌ ' . $r['error']); return; }
        $file = life_rel($r['path']);
    }
    $pdo->prepare("INSERT INTO life_payments (installment_id, policy_id, amount, paid_j, paid_g, method, ref_no, note, file_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$i['id'], $i['policy_id'], $t['amount'], $t['paid_j'], life_jdate($t['paid_j'])[1], $t['method'], ($t['ref'] ?? '') ?: null, 'ثبت از ربات بله', $file, $uid]);
    $payId = intval($pdo->lastInsertId());
    life_refresh_paid($pdo, intval($i['id']));
    life_follow($pdo, intval($i['policy_id']), $uid);
    life_sys_note($pdo, intval($i['policy_id']), 'پرداختِ ' . number_format($t['amount']) . ' ریال برای قسطِ ' . $i['inst_no'] . ' از ربات بله ثبت شد (' . $t['method'] . ', ' . $t['paid_j'] . ').', $uid, intval($i['id']));
    life_write_archive($pdo, intval($i['policy_id']));
    L_reset($chat);
    L_say($chat, '✅ پرداختِ ' . life_money($t['amount']) . ' ریال برای قسطِ ' . L_fa($i['inst_no']) . ' به نامِ شما ثبت شد' . ($file ? ' و فیش بایگانی شد' : '') . '.',
        L_ikb([[['text' => '📄 رسید', 'callback_data' => 'sp:' . $i['id'] . ':' . $payId], ['text' => '🔙 بیمه‌نامه', 'callback_data' => 'p:' . $i['policy_id']]]]));
}

// ---------------------------------------------------------------------
//  ورود
// ---------------------------------------------------------------------
function L_contact($chat, $fromId, $contact) {
    global $pdo;
    if (empty($contact['user_id']) || $fromId === null || strval($contact['user_id']) !== strval($fromId)) {
        L_say($chat, 'لطفاً فقط شماره‌ی خودتان را با دکمه‌ی «📱 ارسالِ شماره‌ی من» بفرستید.', L_contact_kb()); return;
    }
    $phone = life_mobile($contact['phone_number'] ?? '');
    if ($phone === '') { L_say($chat, 'شماره خوانده نشد؛ دوباره امتحان کنید.', L_contact_kb()); return; }
    $staff = lbot_staff_by_phone($pdo, $phone);
    if ($staff) {
        $pdo->prepare("REPLACE INTO life_bot_links (chat_id, kind, user_id, phone, name, remind, linked_at, last_seen) VALUES (?, 'STAFF', ?, ?, ?, 1, NOW(), NOW())")
            ->execute([$chat, $staff['id'], $phone, $staff['full_name']]);
        L_reset($chat);
        L_say($chat, "✅ {$staff['full_name']} عزیز، به‌عنوانِ همکارِ بخشِ بیمه عمر وارد شدید.\nهر کاری این‌جا انجام دهید به نامِ خودتان ثبت می‌شود.", L_staff_menu(lbot_perms($pdo, $staff['id'])));
        return;
    }
    L_set($chat, 'NID', ['phone' => $phone, 'tries' => 0]);
    L_say($chat, "شماره‌ی " . L_fa($phone) . " دریافت شد. ✅\nحالا کد ملیِ بیمه‌گذار (۱۰ رقم) را بنویسید:", ['remove_keyboard' => true]);
}
function L_nid($chat, $text, array $t) {
    global $pdo;
    $nid = life_nid($text);
    if (!preg_match('/^\d{10}$/', $nid)) { L_say($chat, 'کد ملی باید ۱۰ رقم باشد؛ دوباره بنویسید:'); return; }
    $t['tries'] = intval($t['tries'] ?? 0) + 1;
    if ($t['tries'] > 5) { L_reset($chat); L_say($chat, 'تعدادِ تلاش‌ها زیاد شد. لطفاً بعداً دوباره امتحان کنید یا با ما تماس بگیرید.', L_contact_kb()); return; }
    $st = $pdo->prepare("SELECT * FROM life_policies WHERE holder_nid = ?");
    $st->execute([$nid]);
    $all = $st->fetchAll();
    if (!$all) { L_set($chat, 'NID', $t); L_say($chat, 'بیمه‌نامه‌ی عمری با این کد ملی پیدا نشد. کد ملیِ بیمه‌گذار را درست بنویسید:'); return; }
    $phone = $t['phone'];
    $ok = 0; $bad = [];
    foreach ($all as $p) {
        $nums = array_column(life_phone_list($p), 'phone');
        if (!$nums) {
            // شماره‌ای ثبت نشده بود: همین شماره ثبت می‌شود
            $pdo->prepare("UPDATE life_policies SET holder_mobile = ?, updated_at = NOW() WHERE id = ?")->execute([$phone, $p['id']]);
            life_sys_note($pdo, $p['id'], 'شماره‌ی ' . $phone . ' از ربات بله‌ی بیمه عمر (با تأییدِ کد ملی) برای بیمه‌گذار ثبت شد.', null);
            life_write_archive($pdo, $p['id']);
            $ok++;
        } elseif (in_array($phone, $nums, true)) $ok++;
        else $bad[] = $p;
    }
    if (!$ok) {
        foreach ($bad as $p) life_sys_note($pdo, $p['id'], 'تلاشِ ورود به ربات بیمه عمر با شماره‌ی ' . $phone . ' (با شماره‌های ثبت‌شده مطابقت نداشت).', null);
        L_reset($chat);
        L_say($chat, "شماره‌ی شما با شماره‌ی ثبت‌شده برای این بیمه‌نامه یکی نیست. ❗️\nبرای ثبت یا اصلاحِ شماره با کارشناسانِ ما تماس بگیرید.\n\n" . lbot_settings($pdo)['contact_text'], L_contact_kb());
        return;
    }
    $name = $all[0]['holder_name'];
    $pdo->prepare("REPLACE INTO life_bot_links (chat_id, kind, nid, phone, name, remind, linked_at, last_seen) VALUES (?, 'CUSTOMER', ?, ?, ?, 1, NOW(), NOW())")->execute([$chat, $nid, $phone, $name]);
    L_reset($chat);
    $link = lbot_link($pdo, $chat);
    L_say($chat, "✅ {$name} عزیز، خوش آمدید.\nاز این پس اقساطِ بیمه‌نامه‌ی عمرتان را این‌جا می‌بینید و پیش از هر سررسید یادآوری می‌گیرید.", L_customer_menu($link));
    C_list($chat, $link);
}

// ---------------------------------------------------------------------
//  دکمه‌های شیشه‌ای
// ---------------------------------------------------------------------
if (!empty($update['callback_query'])) {
    $cb = $update['callback_query'];
    $chat = $cb['message']['chat']['id'] ?? null;
    lbot_api($pdo, 'answerCallbackQuery', ['callback_query_id' => $cb['id']]);
    if (!$chat) exit;
    $link = lbot_link($pdo, $chat);
    if (!$link) { L_say($chat, 'برای استفاده، اول شماره‌ی خود را بفرستید:', L_contact_kb()); exit; }
    $pdo->prepare("UPDATE life_bot_links SET last_seen = NOW() WHERE chat_id = ?")->execute([$chat]);
    [$cmd, $a1, $a2] = array_pad(explode(':', (string)($cb['data'] ?? ''), 3), 3, null);
    if ($link['kind'] === 'CUSTOMER') {
        if ($cmd === 'cp') C_policy($chat, $link, intval($a1));
        elseif ($cmd === 'ci') C_inst($chat, $link, intval($a1));
        elseif ($cmd === 'cr') {
            $st = $pdo->prepare("SELECT policy_id FROM life_installments WHERE id = ?");
            $st->execute([intval($a1)]);
            if (C_own($link, intval($st->fetchColumn()))) L_send_receipt($chat, intval($a1), 0, null);
        }
        exit;
    }
    $uid = intval($link['user_id']);
    $perms = lbot_perms($pdo, $uid);
    if (!$perms) { $pdo->prepare("DELETE FROM life_bot_links WHERE chat_id = ?")->execute([$chat]); L_say($chat, 'دسترسیِ شما به بیمه عمر برداشته شده است.', L_contact_kb()); exit; }
    $s = L_st($chat);
    switch ($cmd) {
        case 'p': if (lbot_can($perms, 'life-policies:view')) S_policy($chat, $uid, $perms, intval($a1)); break;
        case 'si': if (lbot_can($perms, 'life-policies:view')) S_inst($chat, $perms, intval($a1)); break;
        case 'ov': S_policies($chat, '🔴 بیمه‌نامه‌های معوق', ['status' => 'overdue'], intval($a1), 'ov'); break;
        case 'fu': S_policies($chat, '📅 پیگیری‌های سررسیده', ['followup' => 1, 'sort' => 'next_due'], intval($a1), 'fu'); break;
        case 'zz': if (($s['temp']['q'] ?? '') !== '') S_policies($chat, '🔎 نتیجه‌ی جستجوی «' . mb_substr($s['temp']['q'], 0, 40) . '»', ['q' => $s['temp']['q'], 'sort' => 'name'], intval($a1), 'zz'); break;
        case 'my': S_policies($chat, '👥 پیگیری‌های من', ['follower' => $uid], intval($a1), 'my'); break;
        case 'nl': if (lbot_can($perms, 'life-calls:view')) S_notes($chat, intval($a1)); break;
        case 'fo':
            if (!lbot_can($perms, 'life-calls:create|life-pay:create|life-policies:edit')) break;
            $st = $pdo->prepare("SELECT COUNT(*) FROM life_followers WHERE policy_id = ? AND user_id = ?");
            $st->execute([intval($a1), $uid]);
            if (intval($st->fetchColumn())) $pdo->prepare("DELETE FROM life_followers WHERE policy_id = ? AND user_id = ?")->execute([intval($a1), $uid]);
            else life_follow($pdo, intval($a1), $uid, 0);
            S_policy($chat, $uid, $perms, intval($a1));
            break;
        case 'rm':
            if (!lbot_can($perms, 'life-calls:create')) break;
            $d = life_detail($pdo, intval($a1));
            $target = null;
            foreach ($d['insts'] as $i) if ($i['rem'] > 0) { $target = $i; break; }
            if (!$target) { L_say($chat, 'قسطِ پرداخت‌نشده‌ای نیست.'); break; }
            $n = lbot_remind_inst($pdo, life_policy_row($pdo, intval($a1)), $target + ['pay_id' => $target['pay_id']], 'manual', $target['days'] > 0 ? L_fa($target['days']) . ' روز از سررسیدش گذشته و هنوز پرداخت نشده است' : 'در تاریخِ ' . L_fa($target['due_j']) . ' سررسید می‌شود', $uid);
            L_say($chat, $n ? '✅ یادآوری برای بیمه‌گذار فرستاده شد.' : 'بیمه‌گذار هنوز به ربات وصل نشده است؛ بعد از اولین ورودش یادآوری‌ها خودکار می‌روند.');
            break;
        case 'cn':
            if (!lbot_can($perms, 'life-calls:create')) break;
            L_set($chat, 'CALL', ['pid' => intval($a1)]);
            $btn = array_chunk(array_map(function ($r, $k) { return ['text' => $r, 'callback_data' => 'cs:' . $k]; }, LIFE_CALL_RESULTS, array_keys(LIFE_CALL_RESULTS)), 2);
            $btn[] = [['text' => '📝 فقط یادداشت (بدونِ تماس)', 'callback_data' => 'cs:-']];
            L_say($chat, '☎️ نتیجه‌ی تماس؟', L_ikb($btn));
            break;
        case 'cs':
            if ($s['state'] !== 'CALL') break;
            $t = $s['temp']; $t['result'] = $a1 === '-' ? '' : (LIFE_CALL_RESULTS[intval($a1)] ?? ''); $t['step'] = 'text';
            L_set($chat, 'CALL', $t);
            L_say($chat, '✍️ شرح را بنویسید (چه گفت، قرار چه شد و ...):', L_ikb([[['text' => 'بدونِ توضیح', 'callback_data' => 'ct:-']]]));
            break;
        case 'ct':
            if ($s['state'] !== 'CALL') break;
            $t = $s['temp']; $t['text'] = ''; $t['step'] = 'next'; L_set($chat, 'CALL', $t);
            L_say($chat, '🔔 پیگیریِ بعدی کی باشد؟', L_ikb([[['text' => 'فردا', 'callback_data' => 'cx:1'], ['text' => '۳ روز دیگر', 'callback_data' => 'cx:3'], ['text' => 'یک هفته', 'callback_data' => 'cx:7']], [['text' => 'دو هفته', 'callback_data' => 'cx:14'], ['text' => 'لازم نیست', 'callback_data' => 'cx:0']]]));
            break;
        case 'cx': if ($s['state'] === 'CALL') S_call_save($chat, $uid, $s['temp'], intval($a1)); break;
        case 'pa':
            if (!lbot_can($perms, 'life-pay:create')) break;
            $st = $pdo->prepare("SELECT i.*, " . life_sql_rem('i') . " AS rem FROM life_installments i WHERE i.id = ?");
            $st->execute([intval($a1)]);
            if ($i = $st->fetch()) S_pay_ask($chat, ['iid' => intval($i['id']), 'rem' => intval($i['rem'])], 'amount');
            break;
        case 'pv': if ($s['state'] === 'PAY') { $t = $s['temp']; $t['amount'] = $t['rem']; S_pay_ask($chat, $t, 'date'); } break;
        case 'pd': if ($s['state'] === 'PAY') { $t = $s['temp']; $t['paid_j'] = life_g2j(date('Y-m-d', strtotime('-' . intval($a1) . ' days'))); S_pay_ask($chat, $t, 'method'); } break;
        case 'pm': if ($s['state'] === 'PAY') { $t = $s['temp']; $t['method'] = LIFE_PAY_METHODS[intval($a1)] ?? 'سایر'; S_pay_ask($chat, $t, 'ref'); } break;
        case 'pr': if ($s['state'] === 'PAY') { $t = $s['temp']; $t['ref'] = ''; S_pay_ask($chat, $t, 'file'); } break;
        case 'pf': if ($s['state'] === 'PAY' && ($s['temp']['step'] ?? '') === 'file') S_pay_save($chat, $uid, $s['temp']); break;
        case 'sr': if (lbot_can($perms, 'life-pay:view')) L_send_receipt($chat, intval($a1), 0, $uid); break;
        case 'sp': if (lbot_can($perms, 'life-pay:view')) L_send_receipt($chat, intval($a1), intval($a2), $uid); break;
    }
    exit;
}

// ---------------------------------------------------------------------
//  پیام‌ها
// ---------------------------------------------------------------------
$message = $update['message'] ?? null;
if (!$message || ($message['chat']['type'] ?? 'private') !== 'private') exit;
$chat = $message['chat']['id'];
$text = trim(p2e_digits((string)($message['text'] ?? '')));
lbot_lazy_reminders($pdo);

if (!empty($message['contact'])) { L_contact($chat, $message['from']['id'] ?? null, $message['contact']); exit; }
$link = lbot_link($pdo, $chat);
$s = L_st($chat);
if (!$link) {
    if ($s['state'] === 'NID' && $text !== '' && $text !== '/start') { L_nid($chat, $text, $s['temp']); exit; }
    L_say($chat, '👋 ' . lbot_settings($pdo)['welcome_text'] . "\n\nبیمه‌گذارِ گرامی: برای دیدنِ اقساطِ بیمه‌نامه‌ی عمرتان دکمه‌ی «📱 ارسالِ شماره‌ی من» را بزنید؛ بعد کد ملی را می‌پرسیم.\nهمکاران: با همان شماره‌ای که در پنل دارید وارد شوید.", L_contact_kb());
    exit;
}
$pdo->prepare("UPDATE life_bot_links SET last_seen = NOW() WHERE chat_id = ?")->execute([$chat]);
if ($text === '🚪 خروج') {
    $pdo->prepare("DELETE FROM life_bot_links WHERE chat_id = ?")->execute([$chat]);
    L_reset($chat);
    L_say($chat, 'از ربات خارج شدید. 👋 برای ورودِ دوباره شماره‌تان را بفرستید.', L_contact_kb());
    exit;
}

if ($link['kind'] === 'CUSTOMER') {
    if (!C_policies($link)) { $pdo->prepare("DELETE FROM life_bot_links WHERE chat_id = ?")->execute([$chat]); L_say($chat, 'اطلاعاتِ شما دیگر با بیمه‌نامه‌ای مطابقت ندارد؛ دوباره وارد شوید.', L_contact_kb()); exit; }
    if ($text === '📋 اقساطِ من') C_list($chat, $link);
    elseif ($text === '☎️ تماس با ما') L_say($chat, '☎️ ' . lbot_settings($pdo)['contact_text'], L_customer_menu($link));
    elseif (mb_strpos($text, 'یادآوری') !== false) {
        $on = mb_strpos($text, 'روشن') !== false ? 1 : 0;
        $pdo->prepare("UPDATE life_bot_links SET remind = ? WHERE chat_id = ?")->execute([$on, $chat]);
        $link['remind'] = $on;
        L_say($chat, $on ? '🔔 یادآوریِ سررسیدها روشن شد.' : '🔕 یادآوری‌ها خاموش شد.', L_customer_menu($link));
    } else {
        L_say($chat, ($link['name'] ? $link['name'] . ' عزیز، ' : '') . 'از منوی زیر انتخاب کنید:', L_customer_menu($link));
    }
    exit;
}

// همکار
$uid = intval($link['user_id']);
$perms = lbot_perms($pdo, $uid);
if (!$perms) { $pdo->prepare("DELETE FROM life_bot_links WHERE chat_id = ?")->execute([$chat]); L_say($chat, 'دسترسیِ شما به بیمه عمر برداشته شده است.', L_contact_kb()); exit; }
$menu = ['🔴 معوق‌ها', '📅 پیگیری‌های امروز', '🔎 جستجو', '👥 پیگیری‌های من', '📊 خلاصه', '🔔 خلاصه‌ی روزانه', '/start', '/menu'];
if (in_array($text, $menu, true)) {
    L_reset($chat);
    $view = lbot_can($perms, 'life-policies:view');
    if ($text === '🔴 معوق‌ها' && $view) S_policies($chat, '🔴 بیمه‌نامه‌های معوق', ['status' => 'overdue'], 0, 'ov');
    elseif ($text === '📅 پیگیری‌های امروز' && $view) S_policies($chat, '📅 پیگیری‌های سررسیده', ['followup' => 1, 'sort' => 'next_due'], 0, 'fu');
    elseif ($text === '👥 پیگیری‌های من' && $view) S_policies($chat, '👥 پیگیری‌های من', ['follower' => $uid], 0, 'my');
    elseif ($text === '🔎 جستجو' && $view) { L_set($chat, 'SEARCH'); L_say($chat, '🔎 نام، شماره‌ی بیمه‌نامه، کد ملی یا موبایل را بنویسید:'); }
    elseif ($text === '📊 خلاصه' && lbot_can($perms, 'life-dash:view')) S_summary($chat);
    elseif ($text === '🔔 خلاصه‌ی روزانه') {
        $on = empty($link['remind']) ? 1 : 0;
        $pdo->prepare("UPDATE life_bot_links SET remind = ? WHERE chat_id = ?")->execute([$on, $chat]);
        L_say($chat, $on ? '🔔 خلاصه‌ی روزانه‌ی پیگیری‌ها و معوق‌های شما هر صبح فرستاده می‌شود.' : '🔕 خلاصه‌ی روزانه خاموش شد.', L_staff_menu($perms));
    } else L_say($chat, $link['name'] . ' عزیز، از منوی زیر انتخاب کنید:', L_staff_menu($perms));
    exit;
}
switch ($s['state']) {
    case 'SEARCH':
        if ($text === '') break;
        L_set($chat, 'SEARCH', ['q' => $text]);
        S_policies($chat, '🔎 نتیجه‌ی جستجوی «' . mb_substr($text, 0, 40) . '»', ['q' => $text, 'sort' => 'name'], 0, 'zz');
        exit;
    case 'CALL':
        if (($s['temp']['step'] ?? '') === 'text' && $text !== '') {
            $t = $s['temp']; $t['text'] = mb_substr((string)($message['text'] ?? ''), 0, 2000); $t['step'] = 'next'; L_set($chat, 'CALL', $t);
            L_say($chat, '🔔 پیگیریِ بعدی کی باشد؟', L_ikb([[['text' => 'فردا', 'callback_data' => 'cx:1'], ['text' => '۳ روز دیگر', 'callback_data' => 'cx:3'], ['text' => 'یک هفته', 'callback_data' => 'cx:7']], [['text' => 'دو هفته', 'callback_data' => 'cx:14'], ['text' => 'لازم نیست', 'callback_data' => 'cx:0']]]));
            exit;
        }
        break;
    case 'PAY':
        $t = $s['temp'];
        $step = $t['step'] ?? '';
        if ($step === 'amount') { $a = life_int($text); if ($a <= 0) { L_say($chat, 'مبلغ را با عدد بنویسید (ریال):'); exit; } $t['amount'] = $a; S_pay_ask($chat, $t, 'date'); exit; }
        if ($step === 'date') { $j = life_jdate($text)[0]; if (!$j) { L_say($chat, 'تاریخ را به شکلِ ۱۴۰۵/۰۷/۱۰ بنویسید:'); exit; } $t['paid_j'] = $j; S_pay_ask($chat, $t, 'method'); exit; }
        if ($step === 'ref' && $text !== '') { $t['ref'] = mb_substr($text, 0, 60); S_pay_ask($chat, $t, 'file'); exit; }
        if ($step === 'file' && (!empty($message['photo']) || !empty($message['document']))) { S_pay_save($chat, $uid, $t, $message); exit; }
        if ($step === 'file') { L_say($chat, 'عکسِ فیش را بفرستید یا «بدونِ فیش، ثبت کن» را بزنید.'); exit; }
        break;
}
L_say($chat, $link['name'] . ' عزیز، از منوی زیر انتخاب کنید:', L_staff_menu($perms));
