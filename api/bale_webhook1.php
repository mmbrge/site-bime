<?php
// فایل: api/bale_webhook.php
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update) exit;

$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('bot_last_ping', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = UNIX_TIMESTAMP()");

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bot_enabled', 'bot_token')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
if (($settings['bot_enabled'] ?? '1') === '0' || empty($settings['bot_token'])) exit;
$bot_token = $settings['bot_token'];

$callback = $update['callback_query'] ?? null;
$message = $update['message'] ?? null;

if ($callback) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bot_enabled', 'bot_token')");
    $settings0 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($settings0['bot_enabled'] ?? '1') !== '0' && !empty($settings0['bot_token'])) {
        handle_callback_query($pdo, $callback, $settings0['bot_token']);
    }
    exit;
}

if (!$message) exit; // سایر انواع آپدیت فعلاً پردازش نمی‌شوند

$chat_id    = $message['chat']['id'] ?? null;
$chat_type  = $message['chat']['type'] ?? 'private';
$chat_title = $message['chat']['title'] ?? null;
$incoming_message_id = $message['message_id'] ?? null;
$text = p2e_digits(trim($message['text'] ?? ''));

$sender_user_id  = $message['from']['id'] ?? null;
$first_name      = trim($message['from']['first_name'] ?? '');
$last_name       = trim($message['from']['last_name'] ?? '');
$sender_name     = trim($first_name . ' ' . $last_name);
$sender_username = $message['from']['username'] ?? null;

if (!$chat_id) exit;

// =====================================================================
//  کمکی‌های عمومی بله (ارسال پیام + لاگ برای عیب‌یابی واقعی)
// =====================================================================
function bale_log($label, $data) {
    $logFile = dirname(__DIR__) . '/queue/bale_debug.log';
    $line = '[' . date('Y-m-d H:i:s') . "] $label: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function bale_api($method, $payload, $token) {
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    bale_log($method, ['payload' => $payload, 'response' => $res, 'curl_error' => $err ?: null]);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

function send_msg($chat_id, $text, $token, $reply_markup = null, $reply_to = null) {
    // نام کاربر را به همه‌ی پیام‌های ربات اضافه می‌کنیم (یک‌بار در ابتدای متن)، مگر اینکه
    // خودِ متن از قبل با نامش شروع شده باشد (برای جلوگیری از تکرار)
    global $pdo;
    $personName = null;
    if ($pdo && $text !== '') {
        try {
            $stmt = $pdo->prepare("SELECT full_name FROM persons WHERE bale_chat_id = ?");
            $stmt->execute([$chat_id]);
            $personName = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {}
    }
    if ($personName && strpos($text, $personName) !== 0) {
        $text = "{$personName} عزیز،\n" . $text;
    }

    $payload = ['chat_id' => $chat_id, 'text' => $text];
    if ($reply_markup) $payload['reply_markup'] = $reply_markup;
    if ($reply_to) $payload['reply_to_message_id'] = $reply_to;
    $result = bale_api('sendMessage', $payload, $GLOBALS['bot_token']);

    // آرشیو کامل مکالمه (طرف ربات) در تب «چت‌های ربات» - حتی اگر بعداً کاربر پیامش را پاک کند
    if ($pdo && $text !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id FROM persons WHERE bale_chat_id = ?");
            $stmt->execute([$chat_id]);
            $personId = $stmt->fetchColumn();
            if ($personId) {
                $pdo->prepare("INSERT INTO messages (person_id, sender_type, message_text) VALUES (?, 'BOT', ?)")->execute([$personId, $text]);
            }
        } catch (Exception $e) {}
    }
    return $result;
}

function kb($rows, $oneTime = false) {
    return ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => $oneTime];
}
function kb_remove() { return ['remove_keyboard' => true]; }
function kb_request_contact() {
    return kb([[ ['text' => '📱 اشتراک‌گذاری شماره تماس', 'request_contact' => true] ]], true);
}
// بعد از تایید هویت، کیبورد پایینِ «اشتراک‌گذاری شماره تماس» باید کنار برود؛ به‌جایش
// همین یک دکمه‌ی اعتباری همیشه در پایین چت می‌ماند (چون دکمه‌ی ورود به مینی‌اپ فقط inline می‌شود)
function kb_credit() {
    return kb([['🎨 طراحی و توسعه: گروه برگه (MMBehzadi) - تمامی حقوق محفوظ می‌باشد']]);
}
function e2p_php($s) { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }

// =====================================================================
//  دکمه‌های شیشه‌ای درون‌خطی (inline) برای ساخت پلاک و انتخاب پوشش‌ها
// =====================================================================
function plate_digit_kb() {
    $rows = []; $r = [];
    foreach (['1','2','3','4','5','6','7','8','9','0'] as $d) {
        $r[] = ['text' => $d, 'callback_data' => "pl:d:$d"];
        if (count($r) == 5) { $rows[] = $r; $r = []; }
    }
    if ($r) $rows[] = $r;
    $rows[] = [['text' => '⌫ حذف رقم آخر', 'callback_data' => 'pl:bs']];
    return ['inline_keyboard' => $rows];
}
function plate_letter_kb() {
    $letters = ['ب','پ','ت','ث','ج','د','ز','س','ش','ص','ط','ع','ف','ق','ک','گ','ل','م','ن','و','ه','ی'];
    $rows = []; $r = [];
    foreach ($letters as $l) { $r[] = ['text' => $l, 'callback_data' => "pl:letter:$l"]; if (count($r) == 6) { $rows[] = $r; $r = []; } }
    if ($r) $rows[] = $r;
    return ['inline_keyboard' => $rows];
}
function plate_confirm_kb() {
    return ['inline_keyboard' => [[['text' => '✅ بله، درست است', 'callback_data' => 'pl:yes'], ['text' => '🔁 از نو بساز', 'callback_data' => 'pl:no']]]];
}
function render_plate_text($ft) {
    $p = $ft['prefix'] ?? ''; $l = $ft['letter'] ?? ''; $s = $ft['suffix'] ?? ''; $r = $ft['region'] ?? '';
    return "🚘 در حال ساخت پلاک:\n\n" . e2p_php($r ?: '__') . "ایران   -   " . e2p_php($s ?: '___') . "   " . ($l ?: '_') . "   " . e2p_php($p ?: '__');
}

function ask_plate_step($pdo, $chat_id, $personId, $bot_token, $editMsgId = null) {
    $stmt = $pdo->prepare("SELECT flow_temp FROM persons WHERE id = ?");
    $stmt->execute([$personId]);
    $ft = json_decode($stmt->fetchColumn() ?: '{}', true) ?: [];
    $ft += ['stage' => 'prefix', 'prefix' => '', 'letter' => '', 'suffix' => '', 'region' => ''];

    $stage = $ft['stage'];
    if ($stage === 'prefix') { $prompt = "دو رقم اول پلاک را وارد کنید:"; $kb = plate_digit_kb(); }
    elseif ($stage === 'letter') { $prompt = "حرف پلاک را انتخاب کنید:"; $kb = plate_letter_kb(); }
    elseif ($stage === 'suffix') { $prompt = "سه رقم بعدی پلاک را وارد کنید:"; $kb = plate_digit_kb(); }
    elseif ($stage === 'region') { $prompt = "دو رقم کد «ایران» را وارد کنید:"; $kb = plate_digit_kb(); }
    else { $prompt = "آیا پلاک بالا درست است؟"; $kb = plate_confirm_kb(); }

    $text = render_plate_text($ft) . "\n\n" . $prompt;

    if ($editMsgId) {
        bale_api('editMessageText', ['chat_id' => $chat_id, 'message_id' => $editMsgId, 'text' => $text, 'reply_markup' => $kb], $bot_token);
    } else {
        $sent = bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $kb], $bot_token);
        $ft['msg_id'] = $sent['message_id'] ?? null;
    }
    $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $personId]);
}

function handle_plate_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token) {
    $ft += ['stage' => 'prefix', 'prefix' => '', 'letter' => '', 'suffix' => '', 'region' => ''];
    $parts = explode(':', $data);

    if ($parts[1] === 'd') {
        $digit = $parts[2]; $stage = $ft['stage'];
        if ($stage === 'prefix' && strlen($ft['prefix']) < 2) $ft['prefix'] .= $digit;
        elseif ($stage === 'suffix' && strlen($ft['suffix']) < 3) $ft['suffix'] .= $digit;
        elseif ($stage === 'region' && strlen($ft['region']) < 2) $ft['region'] .= $digit;

        if ($stage === 'prefix' && strlen($ft['prefix']) == 2) $ft['stage'] = 'letter';
        elseif ($stage === 'suffix' && strlen($ft['suffix']) == 3) $ft['stage'] = 'region';
        elseif ($stage === 'region' && strlen($ft['region']) == 2) $ft['stage'] = 'confirm';
    } elseif ($parts[1] === 'bs') {
        $stage = $ft['stage'];
        if ($stage === 'prefix') $ft['prefix'] = substr($ft['prefix'], 0, -1);
        elseif ($stage === 'suffix') $ft['suffix'] = substr($ft['suffix'], 0, -1);
        elseif ($stage === 'region') $ft['region'] = substr($ft['region'], 0, -1);
    } elseif ($parts[1] === 'letter') {
        $ft['letter'] = $parts[2]; $ft['stage'] = 'suffix';
    } elseif ($parts[1] === 'yes') {
        // فرمت نمایش دقیقاً مثل ظاهر فیزیکی پلاک (چپ به راست): کد ایران، سپس سه‌رقم-حرف-دورقم
        $plateStr = $ft['region'] . 'ایران - ' . $ft['suffix'] . ' ' . $ft['letter'] . ' ' . $ft['prefix'];
        $purpose = $ft['purpose'] ?? 'new';

        // ذخیره در «خودروهای من» برای انتخاب سریع در درخواست‌های بعدی
        $stmt = $pdo->prepare("SELECT id FROM person_vehicles WHERE person_id = ? AND plate = ?");
        $stmt->execute([$person['id'], $plateStr]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO person_vehicles (person_id, plate) VALUES (?, ?)")->execute([$person['id'], $plateStr]);
        }
        bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "✅ پلاک ثبت شد: " . $plateStr], $bot_token);
        apply_chosen_plate($pdo, $chatId, $person, $plateStr, $purpose, $bot_token);
        return;
    } elseif ($parts[1] === 'no') {
        $ft = ['stage' => 'prefix', 'prefix' => '', 'letter' => '', 'suffix' => '', 'region' => '', 'msg_id' => $ft['msg_id'] ?? null, 'purpose' => $ft['purpose'] ?? 'new'];
    }

    $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
    ask_plate_step($pdo, $chatId, $person['id'], $bot_token, $msgId);
}

function coverage_kb($selected) {
    $opts = body_coverage_options();
    $rows = [];
    foreach ($opts as $key => $o) {
        $mark = isset($selected[$key]) ? '✅ ' : '⬜ ';
        $extra = (isset($selected[$key]) && $selected[$key] !== true) ? ' (' . $o['tiers'][$selected[$key]] . ')' : '';
        $rows[] = [['text' => $mark . $o['label'] . $extra, 'callback_data' => "cov:toggle:$key"]];
    }
    $rows[] = [['text' => '✅ پایان انتخاب پوشش‌ها', 'callback_data' => 'cov:done']];
    return ['inline_keyboard' => $rows];
}
function coverage_text() {
    $opts = body_coverage_options();
    $txt = "🛡️ پوشش‌های تکمیلی بیمه بدنه پاسارگاد را انتخاب کنید (چند مورد قابل انتخاب است):\n\n";
    foreach ($opts as $o) $txt .= "• *{$o['label']}*: {$o['desc']}\n";
    return $txt;
}

function handle_coverage_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token) {
    $ft += ['selected' => []];
    $parts = explode(':', $data);
    $opts = body_coverage_options();

    if ($parts[1] === 'toggle') {
        $key = $parts[2];
        if (isset($ft['selected'][$key])) {
            unset($ft['selected'][$key]);
            $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
            bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => coverage_text(), 'parse_mode' => 'Markdown', 'reply_markup' => coverage_kb($ft['selected'])], $bot_token);
        } elseif (!empty($opts[$key]['tiers'])) {
            $tierRows = [];
            foreach ($opts[$key]['tiers'] as $tk => $tl) $tierRows[] = [['text' => $tl, 'callback_data' => "cov:tier:$key:$tk:$msgId"]];
            bale_api('sendMessage', ['chat_id' => $chatId, 'text' => "سطح «{$opts[$key]['label']}» را انتخاب کنید:", 'reply_markup' => ['inline_keyboard' => $tierRows]], $bot_token);
        } else {
            $ft['selected'][$key] = true;
            $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
            bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => coverage_text(), 'parse_mode' => 'Markdown', 'reply_markup' => coverage_kb($ft['selected'])], $bot_token);
        }
    } elseif ($parts[1] === 'tier') {
        $key = $parts[2]; $tier = $parts[3]; $originalMsgId = $parts[4] ?? null;
        $ft['selected'][$key] = $tier;
        $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
        if ($originalMsgId) {
            bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $originalMsgId, 'text' => coverage_text(), 'parse_mode' => 'Markdown', 'reply_markup' => coverage_kb($ft['selected'])], $bot_token);
        }
        bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "✅ ثبت شد."], $bot_token);
    } elseif ($parts[1] === 'done') {
        $pdo->prepare("UPDATE policy_cases SET selected_coverages = ? WHERE id = ?")->execute([json_encode($ft['selected'], JSON_UNESCAPED_UNICODE), $person['active_case_id']]);
        $pdo->prepare("UPDATE persons SET flow_temp = NULL, conversation_state = 'AWAITING_CAR_VALUE' WHERE id = ?")->execute([$person['id']]);
        bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "✅ پوشش‌های انتخابی ثبت شد."], $bot_token);
        send_msg($chatId, "ارزش حدودی خودرو خود را به تومان وارد کنید (اگر نمی‌دانید، دکمه زیر را بزنید):", $bot_token, kb([['🧮 محاسبه خودکار توسط کارشناس']]));
    }
}

function handle_callback_query($pdo, $callback, $bot_token) {
    $data = $callback['data'] ?? '';
    $chatId = $callback['message']['chat']['id'] ?? null;
    $msgId = $callback['message']['message_id'] ?? null;
    $callbackId = $callback['id'] ?? null;

    bale_api('answerCallbackQuery', ['callback_query_id' => $callbackId], $bot_token);
    if (!$chatId) return;

    $stmt = $pdo->prepare("SELECT * FROM persons WHERE bale_chat_id = ?");
    $stmt->execute([$chatId]);
    $person = $stmt->fetch();
    if (!$person) return;

    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];

    if (strpos($data, 'pl:') === 0) { handle_plate_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token); return; }
    if (strpos($data, 'cov:') === 0) { handle_coverage_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token); return; }
    if (strpos($data, 'doc:pick:') === 0) { handle_doc_pick_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token); return; }
    if (strpos($data, 'redoc:') === 0) {
        $caseId = intval(substr($data, 6));
        $pdo->prepare("UPDATE persons SET active_case_id = ? WHERE id = ?")->execute([$caseId, $person['id']]);
        bale_api('editMessageText', ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "در حال آماده‌سازی ارسال دوباره..."], $bot_token);
        $person['active_case_id'] = $caseId;
        start_docs_collection($pdo, $chatId, $person, $bot_token);
        return;
    }
    if (strpos($data, 'mypolicy:') === 0) {
        $parts = explode(':', $data);
        $subAction = $parts[1]; $caseId = intval($parts[2]);
        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ?");
        $stmt->execute([$caseId, $person['id']]);
        $caseRow = $stmt->fetch();
        if ($caseRow && $subAction === 'file') {
            send_policy_file($chatId, $caseRow, $bot_token);
        }
        return;
    }
    if (strpos($data, 'vh:') === 0) {
        $parts = explode(':', $data);
        if ($parts[1] === 'del') {
            $vehicleId = intval($parts[2]);
            $pdo->prepare("DELETE FROM person_vehicles WHERE id = ? AND person_id = ?")->execute([$vehicleId, $person['id']]);
            bale_api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId], $bot_token);
            show_my_vehicles($pdo, $chatId, $person, $bot_token);
        }
        return;
    }
    if ($data === 'menu:help') {
        send_msg($chatId, "ℹ️ راهنما:\nهمه‌ی امکانات (ثبت درخواست بیمه، بازدید سلامت، بیمه‌نامه‌های من، پلاک‌های من، استعلام حق بیمه و ارتباط با کارشناس) از طریق دکمه‌ی «🚀 ورود به مینی‌اپ» در دسترس است.", $bot_token, main_menu_kb($pdo, $chatId));
        return;
    }
}

// ---- برچسب‌های دکمه‌های ثابت (برای مقایسه‌ی متن پیام ورودی) ----
define('BTN_ORDER',    '📝 ثبت درخواست بیمه');
define('BTN_MY_POLICIES', '📁 بیمه‌نامه‌های من');
define('BTN_MY_VEHICLES', '🚙 پلاک‌های من');
define('BTN_PREMIUM_INQUIRY', '💰 استعلام حق بیمه');
define('BTN_HEALTH',   '🚗 بازدید سلامت خودرو');
define('BTN_SUPPORT',  '💬 ارتباط با کارشناس');
define('BTN_HELP',     'ℹ️ راهنما');
define('BTN_RECHECK',  '🔄 بررسی مجدد وضعیت');
define('BTN_END_CHAT', '🔚 پایان گفتگو با کارشناس');
define('BTN_THIRDPARTY','ثالث');
define('BTN_BODY',     'بدنه');
define('BTN_BACK',     '↩️ بازگشت');
define('BTN_HOME',     '🏠 بازگشت به منو');
define('BTN_SELF',     'خودم هستم');
define('BTN_OTHER',    'یکی از بستگان درجه یک');
define('BTN_USE_SAVED', '✅ استفاده از اطلاعات قبلی');
define('BTN_ENTER_NEW', '📝 وارد کردن اطلاعات جدید');

function kb_grid($items, $perRow = 2) {
    $rows = []; $r = [];
    foreach ($items as $it) { $r[] = $it; if (count($r) == $perRow) { $rows[] = $r; $r = []; } }
    if ($r) $rows[] = $r;
    return $rows;
}
function main_menu_kb($pdo, $chat_id) {
    $stmt = $pdo->prepare("SELECT id FROM persons WHERE bale_chat_id = ?");
    $stmt->execute([$chat_id]);
    $personId = $stmt->fetchColumn();

    $row1 = [['text' => 'ℹ️ راهنما', 'callback_data' => 'menu:help']];
    $rows = [$row1];
    $siteUrl = get_site_url($pdo);
    if ($personId && $siteUrl) {
        $token = get_or_create_main_app_token($pdo, $personId);
        $row1[] = ['text' => '🚀 ورود به مینی‌اپ', 'web_app' => ['url' => $siteUrl . '/webapp/app/?t=' . $token]];
        $rows = [$row1, [['text' => '📝 ثبت درخواست بیمه', 'web_app' => ['url' => $siteUrl . '/webapp/order/index_order.html?t=' . $token]]]];
    }
    return ['inline_keyboard' => $rows];
}
function limited_menu_kb() {
    return kb(kb_grid([BTN_SUPPORT, BTN_RECHECK, BTN_HELP], 2));
}
function insurance_choice_kb() {
    return kb([[BTN_THIRDPARTY, BTN_BODY], [BTN_BACK]]);
}
function self_or_other_kb() {
    return kb(kb_grid([BTN_SELF, BTN_OTHER, BTN_BACK], 2));
}
function support_mode_kb() {
    return kb([[BTN_END_CHAT]]);
}

// =====================================================================
//  مسیر ۱: پیام از گروه (کارکنان) -> همان پایپ‌لاین قبلیِ خواندن بیمه‌نامه‌ها
// =====================================================================
function getFileUrl($file_id, $token) {
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/getFile?file_id=" . $file_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (isset($res['ok']) && $res['ok']) {
        return "https://tapi.bale.ai/file/bot" . $token . "/" . $res['result']['file_path'];
    }
    return null;
}

function handle_staff_group_message($pdo, $message, $chat_id, $chat_type, $chat_title,
                                      $incoming_message_id, $sender_user_id, $sender_name,
                                      $sender_username, $bot_token) {

    function runExtractionPipeline($absoluteFilePath) {
        $python_env  = "/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3";
        $script_path = "/home/besiteir/ocr-paddle/test_pipeline.py";
        $command = "export PYTHONIOENCODING=utf8; " . escapeshellarg($python_env) . " " .
                   escapeshellarg($script_path) . " " . escapeshellarg($absoluteFilePath) . " 2>&1";
        $output = shell_exec($command);
        if ($output !== null && preg_match('/\{.*\}/s', $output, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return ['ok' => false, 'error' => 'خروجی نامعتبر از پایپ‌لاین پایتون', 'raw' => $output];
    }

    $file_id = null;
    $file_name = '';
    if (isset($message['document'])) {
        $file_id = $message['document']['file_id'];
        $file_name = $message['document']['file_name'] ?? 'document.pdf';
    } elseif (isset($message['photo'])) {
        $photos = $message['photo'];
        $file_id = $photos[count($photos) - 1]['file_id'];
        $file_name = 'photo_' . time() . '.jpg';
    }

    if (!$file_id) return; // پیام متنی داخل گروه فعلاً نادیده گرفته می‌شود

    $file_url = getFileUrl($file_id, $bot_token);
    if (!$file_url) {
        send_msg($chat_id, "❌ متاسفانه در دانلود فایل از سرور مشکلی پیش آمد.", $bot_token, null, $incoming_message_id);
        return;
    }

    $file_content = file_get_contents($file_url);
    $pending_dir = dirname(__DIR__) . '/queue/pending';
    if (!file_exists($pending_dir)) mkdir($pending_dir, 0777, true);

    $safe_filename = time() . "_" . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $file_name);
    $physical_path = $pending_dir . '/' . $safe_filename;
    $db_path = 'queue/pending/' . $safe_filename;
    file_put_contents($physical_path, $file_content);

    $status = 'FAILED';
    $extractedJson = null;
    $ext = pathinfo($safe_filename, PATHINFO_EXTENSION);

    if (strtolower($ext) === 'pdf') {
        $pipelineResult = runExtractionPipeline($physical_path);
        if (!empty($pipelineResult['ok'])) {
            $status = 'DONE';
            $extractedJson = json_encode($pipelineResult['extracted'] ?? [], JSON_UNESCAPED_UNICODE);
        } else {
            $extractedJson = json_encode(['error' => $pipelineResult['error'] ?? 'خطای نامشخص پردازش'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        $status = 'PENDING';
    }

    $notify_message_id = null;
    $extractedArr = $status === 'DONE' ? (json_decode($extractedJson, true) ?: []) : [];
    $isIntroduction = ($status === 'DONE' && ($extractedArr['ins_type'] ?? '') === 'معرفی‌نامه');

    if ($isIntroduction) {
        $notifyText = "📋 معرفی‌نامه آقای / خانم *" . ($extractedArr['insured_name'] ?: 'نامشخص') . "* با موفقیت دریافت شد ✅\nبه محض تایید نهایی توسط کارشناس، ثبت خواهد شد.";
        $sent = bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => $notifyText, 'reply_to_message_id' => $incoming_message_id, 'parse_mode' => 'Markdown'], $bot_token);
        $notify_message_id = $sent['message_id'] ?? null;
    } elseif ($status === 'DONE') {
        send_msg($chat_id, "✅ مدرک شما خوانده شد و در «صف پردازش هوشمند» منتظر تایید نهایی کارشناس است.", $bot_token, null, $incoming_message_id);
    } elseif ($status === 'FAILED') {
        send_msg($chat_id, "⚠️ مدرک شما دریافت شد اما هوش مصنوعی نتوانست اطلاعات را به‌طور خودکار بخواند. کارشناس به‌صورت دستی آن را بررسی می‌کند.", $bot_token, null, $incoming_message_id);
    } else {
        send_msg($chat_id, "✅ مدرک شما دریافت شد و در صف بررسی دستی قرار گرفت.", $bot_token, null, $incoming_message_id);
    }

    $stmt = $pdo->prepare("
        INSERT INTO processing_queue
            (file_name, file_path, file_type, status, extracted_data,
             uploaded_by, sender_user_id, sender_name, sender_username, chat_type, chat_title,
             tg_message_id, tg_notify_message_id, processed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $file_name, $db_path, $ext, $status, $extractedJson,
        $chat_id, $sender_user_id, $sender_name !== '' ? $sender_name : null, $sender_username,
        $chat_type, $chat_title, $incoming_message_id, $notify_message_id,
        ($status === 'PENDING') ? null : date('Y-m-d H:i:s'),
    ]);
}

// =====================================================================
//  مسیر ۲: پیام خصوصی مشتری -> ماشین‌حالت مکالمه
// =====================================================================

// ---- تیکت پشتیبانی: کمکی‌ها ----
function find_open_ticket($pdo, $chat_id) {
    $stmt = $pdo->prepare("SELECT id FROM tickets WHERE chat_id = ? AND status = 'OPEN' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$chat_id]);
    return $stmt->fetchColumn() ?: null;
}

function create_ticket($pdo, $chat_id, $person_id, $sender_name, $sender_username) {
    $stmt = $pdo->prepare("INSERT INTO tickets (chat_id, person_id, sender_name, sender_username, last_message_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$chat_id, $person_id, $sender_name ?: null, $sender_username]);
    $ticketId = $pdo->lastInsertId();
    notify_admin($pdo, "💬 تیکت پشتیبانی جدید از طرف {$sender_name}.");
    return $ticketId;
}

function log_ticket_message($pdo, $ticket_id, $message_text, $file_path, $tg_message_id) {
    $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, file_path, tg_message_id) VALUES (?, 'CUSTOMER', ?, ?, ?)");
    $stmt->execute([$ticket_id, $message_text, $file_path, $tg_message_id]);
    $pdo->prepare("UPDATE tickets SET last_message_at = NOW() WHERE id = ?")->execute([$ticket_id]);
}

// ---- شناسایی مشتری بر اساس کد ملی + اشتراک شماره، و مسیردهی نهایی ----
function identify_and_route($pdo, $chat_id, $national_code, $mobile, $bot_token) {
    $stmt = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
    $stmt->execute([$national_code]);
    $person_id = $stmt->fetchColumn();

    if ($person_id) {
        $pdo->prepare("UPDATE persons SET bale_chat_id = ?, mobile_number = COALESCE(?, mobile_number) WHERE id = ?")
            ->execute([$chat_id, $mobile, $person_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO persons (national_code, full_name, bale_chat_id, mobile_number, conversation_state) VALUES (?, '', ?, ?, 'START')");
        $stmt->execute([$national_code, $chat_id, $mobile]);
        $person_id = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT full_name FROM persons WHERE id = ?");
    $stmt->execute([$person_id]);
    $fullName = $stmt->fetchColumn();
    $greetName = $fullName ? ($fullName . ' عزیز، ') : '';

    // آیا معرفی‌نامه/پرونده‌ای برای این کد ملی وجود دارد؟
    $stmt = $pdo->prepare("
        SELECT i.id AS intro_id, i.max_quota, i.used_quota, i.group_chat_id, i.personnel_visited_notified
        FROM introductions i WHERE i.person_id = ?
        ORDER BY i.created_at DESC LIMIT 1
    ");
    $stmt->execute([$person_id]);
    $intro = $stmt->fetch();

    if ($intro) {
        // مرحله‌ی «مراجعه پرسنل» - همان پیام اصلیِ معرفی‌نامه در گروه ویرایش می‌شود، نه پیام جدید
        if (!$intro['personnel_visited_notified'] && $intro['group_chat_id']) {
            $pdo->prepare("UPDATE introductions SET personnel_visited_notified = 1 WHERE id = ?")->execute([$intro['intro_id']]);
            sync_intro_group_message($pdo, $intro['intro_id'], $bot_token);
        }

        $maxQuota = intval($intro['max_quota'] ?? 4);
        $usedQuota = intval($intro['used_quota'] ?? 0);
        if ($usedQuota < $maxQuota) {
            $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person_id]);
            set_chat_menu_button($pdo, $chat_id, $person_id, $bot_token);
            send_msg($chat_id, "{$greetName}✅ هویت شما تایید شد، خوش آمدید!\nاز منوی زیر می‌توانید سفارش خود را ثبت کنید.", $bot_token, main_menu_kb($pdo, $chat_id));
            send_msg($chat_id, "برای استفاده از امکانات ربات، از دکمه‌ی «🚀 ورود به مینی‌اپ» در پیام بالا استفاده کنید.", $bot_token, kb_remove());
        } else {
            $pdo->prepare("UPDATE persons SET conversation_state = 'LIMITED_MENU' WHERE id = ?")->execute([$person_id]);
            send_msg($chat_id, "{$greetName}سقف صدور بیمه‌نامه با معرفی‌نامه فعلی شما تکمیل شده است.\nبرای معرفی‌نامه جدید با واحد منابع انسانی هماهنگ کنید.", $bot_token, limited_menu_kb());
        }
        return;
    }

    // پرونده‌ای نیست؛ آیا در صف پردازش (هنوز تاییدنشده) هست؟
    $stmt = $pdo->prepare("
        SELECT id FROM processing_queue
        WHERE JSON_UNQUOTE(JSON_EXTRACT(extracted_data, '$.national_id')) = ?
        ORDER BY uploaded_at DESC LIMIT 1
    ");
    $stmt->execute([$national_code]);
    $pendingQueueId = $stmt->fetchColumn();

    $pdo->prepare("UPDATE persons SET conversation_state = 'LIMITED_MENU' WHERE id = ?")->execute([$person_id]);

    if ($pendingQueueId) {
        send_msg($chat_id, "📋 معرفی‌نامه شما صادر شده است ولی هنوز توسط کارشناسان تایید نشده است.\nلطفاً در صورت عجله، از دکمه‌ی «ارتباط با کارشناس» استفاده کنید.", $bot_token, limited_menu_kb());
    } else {
        send_msg($chat_id, "متاسفانه هنوز برای شما معرفی‌نامه‌ای ثبت نشده است.\nلطفاً از طریق واحد منابع انسانی (آقای علی‌نیا) پیگیر باشید.", $bot_token, limited_menu_kb());
    }
}

// ---- کمکی‌های «صدور بیمه‌نامه» (ایجاد پرونده + جمع‌آوری مدارک با گرید دکمه‌ای) ----
function build_docs_grid_kb($pdo, $case) {
    $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
    $stmt = $pdo->prepare("SELECT doc_key FROM case_documents WHERE case_id = ? AND status IN ('PENDING','APPROVED')");
    $stmt->execute([$case['id']]);
    $satisfied = array_column($stmt->fetchAll(), 'doc_key');
    $rows = []; $r = [];
    foreach ($required as $key => $label) {
        $mark = in_array($key, $satisfied) ? '✅ ' : '⬜ ';
        $btn = ['text' => $mark . $label, 'callback_data' => "doc:pick:$key"];
        // برچسب‌های طولانی تنها در یک ردیف کامل قرار می‌گیرند تا بریده/تودرتو نشوند
        if (mb_strlen($label) > 26) {
            if ($r) { $rows[] = $r; $r = []; }
            $rows[] = [$btn];
            continue;
        }
        $r[] = $btn;
        if (count($r) == 2) { $rows[] = $r; $r = []; }
    }
    if ($r) $rows[] = $r;
    return ['inline_keyboard' => $rows];
}

function all_required_docs_satisfied($pdo, $case) {
    $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
    $stmt = $pdo->prepare("SELECT doc_key FROM case_documents WHERE case_id = ? AND status IN ('PENDING','APPROVED')");
    $stmt->execute([$case['id']]);
    $satisfied = array_column($stmt->fetchAll(), 'doc_key');
    return count(array_diff(array_keys($required), $satisfied)) === 0;
}

function build_case_summary_text($case, $person, $forDocsGrid = false) {
    $typeFa = insurance_type_fa($case['insurance_type']);
    $text = "📋 خلاصه‌ی درخواست بیمه‌نامه شما:\n\n";
    $text .= "🔹 نوع بیمه: {$typeFa}\n";
    $text .= "🔹 پلاک: " . ($case['plate'] ?: '-') . "\n";
    if ($case['insurance_type'] === 'THIRDPARTY' && $case['liability_limit']) {
        $text .= "🔹 سقف تعهد مالی: " . number_format($case['liability_limit'] / 10) . " تومان\n";
    }
    if ($case['insurance_type'] === 'BODY') {
        $covs = json_decode($case['selected_coverages'] ?: '{}', true) ?: [];
        if ($covs) {
            $opts = body_coverage_options();
            $labels = [];
            foreach ($covs as $k => $v) $labels[] = $opts[$k]['label'] ?? $k;
            $text .= "🔹 پوشش‌های انتخابی: " . implode('، ', $labels) . "\n";
        }
        if ($case['estimated_car_value']) $text .= "🔹 ارزش تقریبی خودرو: " . number_format($case['estimated_car_value']) . " تومان\n";
    }
    $text .= "\n👤 دارنده معرفی‌نامه: {$person['full_name']}\n";
    $text .= "\n👤 اطلاعات بیمه‌گذار (نسبت: {$case['insured_relationship']}):\n";
    $text .= "نام: {$case['insured_name']}\n";
    $text .= "کد ملی: {$case['insured_national_id']}\n";
    $text .= "تاریخ تولد: " . ($case['insured_birth_date'] ?: '-') . "\n";
    $text .= "آدرس: " . ($case['insured_address'] ?: '-') . "\n";
    $text .= "موبایل: " . ($case['insured_phone'] ?: '-') . "\n";
    $text .= "کد پستی: " . ($case['insured_postal_code'] ?: '-') . "\n";
    if ($forDocsGrid) $text .= "\n📎 اکنون هرکدام از موارد زیر را که آماده دارید، انتخاب و عکس/فایل آن را ارسال کنید:";
    return $text;
}

function start_docs_collection($pdo, $chat_id, $person, $bot_token) {
    $case = get_case_row($pdo, $person['active_case_id']);
    // ابتدا کیبورد پایین (اعداد سقف تعهد/گزینه‌های مرحله قبل) را پاک می‌کنیم؛
    // چون دکمه‌های inline و کیبورد پایین دو عنصر جدا از هم‌اند و با یک پیام قابل ترکیب نیستند
    send_msg($chat_id, build_case_summary_text($case, $person, true), $bot_token, kb_remove());

    $sent = bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => "📎 مدارک لازم:", 'reply_markup' => build_docs_grid_kb($pdo, $case)], $bot_token);
    $ft = ['grid_msg_id' => $sent['message_id'] ?? null, 'active_doc_key' => null, 'active_doc_label' => null, 'prompt_msg_id' => null];
    $pdo->prepare("UPDATE persons SET conversation_state = 'COLLECTING_DOCS', flow_temp = ? WHERE id = ?")
        ->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
}

function handle_doc_pick_callback($pdo, $data, $chatId, $person, $ft, $msgId, $bot_token) {
    $key = substr($data, strlen('doc:pick:'));
    $case = get_case_row($pdo, $person['active_case_id']);
    $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
    if (!isset($required[$key])) return;

    $ft['active_doc_key'] = $key;
    $ft['active_doc_label'] = $required[$key];
    $ft['grid_msg_id'] = $msgId;
    $sent = bale_api('sendMessage', ['chat_id' => $chatId, 'text' => "📎 لطفاً عکس یا فایل «{$required[$key]}» را ارسال کنید:"], $bot_token);
    $ft['prompt_msg_id'] = $sent['message_id'] ?? null;
    $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
}

function save_incoming_case_file($message, $bot_token, $nationalId = null, $plate = null) {
    $file_id = null; $file_name = '';
    if (isset($message['document'])) {
        $file_id = $message['document']['file_id'];
        $file_name = $message['document']['file_name'] ?? 'document';
    } elseif (isset($message['photo'])) {
        $photos = $message['photo'];
        $file_id = $photos[count($photos) - 1]['file_id'];
        $file_name = 'photo_' . time() . '.jpg';
    }
    if (!$file_id) return null;

    $file_url = getFileUrl($file_id, $bot_token);
    if (!$file_url) return null;

    $siteRoot = dirname(__DIR__);
    $tmpDir = $nationalId
        ? build_temp_plate_path($siteRoot, time(), $nationalId, $plate)
        : ($siteRoot . '/queue/case_uploads'); // fallback ایمنی اگر کد ملی در دسترس نبود
    if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
    $ext = pathinfo($file_name, PATHINFO_EXTENSION) ?: 'jpg';
    $safeName = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $destPath = $tmpDir . '/' . $safeName;
    file_put_contents($destPath, file_get_contents($file_url));
    compress_image_if_needed($destPath);
    return ltrim(str_replace($siteRoot, '', $destPath), '/');
}

function handle_case_document_upload($pdo, $message, $chat_id, $person, $bot_token) {
    $caseId = $person['active_case_id'];
    if (!$caseId) return;
    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
    $docKey = $ft['active_doc_key'] ?? null;
    if (!$docKey) {
        send_msg($chat_id, "لطفاً ابتدا یکی از دکمه‌های بالا را انتخاب کنید، سپس فایل مربوط به آن را بفرستید.", $bot_token);
        return;
    }
    $docLabel = $ft['active_doc_label'] ?? $docKey;
    $case = get_case_row($pdo, $caseId);

    $relativePath = save_incoming_case_file($message, $bot_token, $case['insured_national_id'] ?: $person['national_code'], $case['plate']);
    if (!$relativePath) { send_msg($chat_id, "لطفاً یک عکس یا فایل معتبر ارسال کنید:", $bot_token); return; }

    $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'PENDING')")
        ->execute([$caseId, $docKey, $docLabel, $relativePath]);

    // پیام «لطفاً ارسال کنید» و خودِ عکس/فایلِ کاربر را حذف می‌کنیم تا آخرین پیام
    // در چت دوباره همان گرید دکمه‌ها (با تیک به‌روزشده) باشد، نه انبوهی پیام پراکنده
    if (!empty($ft['prompt_msg_id'])) bale_api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $ft['prompt_msg_id']], $bot_token);
    if (!empty($message['message_id'])) bale_api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message['message_id']], $bot_token);

    $ft['active_doc_key'] = null; $ft['active_doc_label'] = null; $ft['prompt_msg_id'] = null;
    $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);

    $case = get_case_row($pdo, $caseId);
    if (!empty($ft['grid_msg_id'])) {
        $newKb = build_docs_grid_kb($pdo, $case);
        bale_api('editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $ft['grid_msg_id'], 'reply_markup' => $newKb], $bot_token);
    }

    if (all_required_docs_satisfied($pdo, $case)) {
        send_msg($chat_id, "✅ همه‌ی مدارک لازم دریافت شد.\n\n" . build_case_summary_text($case, $person, false) . "\n\nآیا از صحت همه‌ی اطلاعات بالا و مدارک ارسالی مطمئن هستید؟", $bot_token,
            kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], ['↩️ بازگشت به منو']]));
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_FINAL_CONFIRM' WHERE id = ?")->execute([$person['id']]);
    } else {
        send_msg($chat_id, "✅ «{$docLabel}» دریافت شد. مورد بعدی را از دکمه‌های بالا انتخاب کنید.", $bot_token);
    }
}

// ---- تایید نهایی، ویرایش اطلاعات، «بیمه‌نامه‌های من» ----
const EDITABLE_FIELDS = [
    'plate' => ['label' => 'پلاک', 'type' => 'plate'],
    'insured_name' => ['label' => 'نام بیمه‌گذار', 'type' => 'text'],
    'insured_birth_date' => ['label' => 'تاریخ تولد', 'type' => 'birth_date'],
    'insured_address' => ['label' => 'آدرس', 'type' => 'text'],
    'insured_phone' => ['label' => 'موبایل', 'type' => 'phone'],
    'insured_postal_code' => ['label' => 'کد پستی', 'type' => 'postal'],
];

function edit_field_menu_kb() {
    return kb_grid_with_back(array_map(fn($f) => $f['label'], EDITABLE_FIELDS));
}
function kb_grid_with_back($labels) {
    $rows = kb_grid($labels, 2);
    $rows[] = [BTN_BACK];
    return kb($rows);
}

function validate_field_value($type, $text) {
    if ($type === 'birth_date') return (bool) preg_match('/^1[34]\d{2}\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/', $text);
    if ($type === 'phone') return (bool) preg_match('/^09\d{9}$/', $text);
    if ($type === 'postal') return (bool) preg_match('/^\d{10}$/', $text);
    if ($type === 'text') return mb_strlen($text) >= 2;
    return $text !== '';
}

function display_plate_compact($plate) {
    if (!$plate) return 'بدون پلاک';
    $p = str_replace('ایران', '', $plate);
    $p = preg_replace('/\s+/', ' ', $p);
    return trim($p, " -");
}

function list_my_policies($pdo, $chat_id, $person, $bot_token) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE person_id = ? ORDER BY created_at DESC");
    $stmt->execute([$person['id']]);
    $cases = $stmt->fetchAll();
    if (!$cases) { send_msg($chat_id, "شما هنوز درخواست بیمه‌ای ثبت نکرده‌اید.", $bot_token, main_menu_kb($pdo, $chat_id)); return; }

    $statusFa = ['REGISTERED' => 'ثبت شده', 'DOCS_PENDING' => 'در انتظار اصلاح', 'DOCS_REVIEW' => 'در انتظار تایید',
                 'ISSUING' => 'در حال صدور', 'ISSUED' => '✅ صادر شده', 'REJECTED' => 'رد شده'];

    $rows = [];
    foreach ($cases as $c) {
        $statusLabel = $statusFa[$c['status']] ?? $c['status'];
        // پلاک را تنها و با عرض کامل می‌گذاریم تا متنش هرگز بریده/تنگ نشود
        $rows[] = [['text' => display_plate_compact($c['plate']), 'callback_data' => "mypolicy:info:{$c['id']}"]];
        $rows[] = [
            ['text' => insurance_type_fa($c['insurance_type']), 'callback_data' => "mypolicy:info:{$c['id']}"],
            ['text' => $statusLabel, 'callback_data' => $c['status'] === 'ISSUED' ? "mypolicy:file:{$c['id']}" : "mypolicy:info:{$c['id']}"],
        ];
    }
    bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => "📁 بیمه‌نامه‌های من:\nبرای دریافت فایل، روی وضعیتِ «✅ صادر شده» بزنید.", 'reply_markup' => ['inline_keyboard' => $rows]], $bot_token);
}

function send_policy_file($chat_id, $caseRow, $bot_token) {
    if ($caseRow['status'] !== 'ISSUED' || !$caseRow['issued_file_path']) return false;
    $absPath = dirname(__DIR__) . '/' . $caseRow['issued_file_path'];
    if (!file_exists($absPath)) return false;
    $ch = curl_init("https://tapi.bale.ai/bot" . $bot_token . "/sendDocument");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chat_id, 'document' => new CURLFile($absPath), 'caption' => "بیمه‌نامه {$caseRow['unique_code']}"]);
    curl_exec($ch); curl_close($ch);
    return true;
}

// ---- گام‌های میانیِ فرآیند سفارش (پروفایل، پلاک، سقف تعهد، پوشش‌ها، مدارک) ----
function get_case_addressee($pdo, $caseId) {
    $stmt = $pdo->prepare("SELECT insured_relationship FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    return relationship_addressee($stmt->fetchColumn());
}

// (get_profile_key اکنون در _case_helpers.php مشترک تعریف شده تا وب‌اپ هم از آن استفاده کند)

function goto_profile_reuse_step($pdo, $chat_id, $person, $bot_token) {
    $case = get_case_row($pdo, $person['active_case_id']);
    $allSaved = json_decode($person['saved_profile'] ?: '{}', true) ?: [];
    $key = get_profile_key($case);
    $saved = $allSaved[$key] ?? [];
    $addr = get_case_addressee($pdo, $person['active_case_id']);
    if (!empty($saved['birth_date']) || !empty($saved['address'])) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PROFILE_REUSE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "اطلاعات هویتی ذخیره‌شده‌ی قبلی {$addr} پیدا شد. می‌خواهید از همان استفاده کنید یا اطلاعات جدید وارد می‌کنید؟", $bot_token, kb([[BTN_USE_SAVED], [BTN_ENTER_NEW]]));
    } else {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_BIRTH_DATE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "تاریخ تولد {$addr} را با فرمت 1405/02/03 وارد کنید:", $bot_token, kb_remove());
    }
}

function start_plate_builder($pdo, $chat_id, $person, $bot_token, $purpose = 'new') {
    if ($purpose !== 'add_only') {
        $stmt = $pdo->prepare("SELECT id, plate FROM person_vehicles WHERE person_id = ? ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        $vehicles = $stmt->fetchAll();

        if ($vehicles) {
            $rows = kb_grid(array_column($vehicles, 'plate'), 1);
            $rows[] = ['🆕 خودروی جدید'];
            $rows[] = [BTN_BACK, BTN_HOME];
            $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_VEHICLE_CHOICE', flow_temp = ? WHERE id = ?")
                ->execute([json_encode(['purpose' => $purpose], JSON_UNESCAPED_UNICODE), $person['id']]);
            send_msg($chat_id, "پلاک این خودرو را قبلاً ثبت کرده‌اید. یکی را انتخاب کنید یا خودروی جدید وارد کنید:", $bot_token, kb($rows));
            return;
        }
    }

    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PLATE_BUILD', flow_temp = ? WHERE id = ?")
        ->execute([json_encode(['purpose' => $purpose], JSON_UNESCAPED_UNICODE), $person['id']]);
    ask_plate_step($pdo, $chat_id, $person['id'], $bot_token);
}

function apply_chosen_plate($pdo, $chat_id, $person, $plateStr, $purpose, $bot_token) {
    if ($purpose === 'free_health') {
        $pdo->prepare("UPDATE persons SET flow_temp = NULL, conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
        launch_health_webapp($pdo, $chat_id, $person, 'free', null, $plateStr, $bot_token);
    } elseif ($purpose === 'edit') {
        $pdo->prepare("UPDATE policy_cases SET plate = ? WHERE id = ?")->execute([$plateStr, $person['active_case_id']]);
        $case = get_case_row($pdo, $person['active_case_id']);
        $pdo->prepare("UPDATE persons SET flow_temp = NULL, conversation_state = 'AWAITING_FINAL_CONFIRM' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "✅ بروزرسانی شد.\n\n" . build_case_summary_text($case, $person) . "\n\nآیا از صحت اطلاعات بالا مطمئن هستید؟", $bot_token, kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], [BTN_BACK, BTN_HOME]]));
    } elseif ($purpose === 'add_only') {
        $pdo->prepare("UPDATE persons SET flow_temp = NULL, conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "✅ پلاک ذخیره شد.", $bot_token, main_menu_kb($pdo, $chat_id));
    } else {
        $pdo->prepare("UPDATE policy_cases SET plate = ? WHERE id = ?")->execute([$plateStr, $person['active_case_id']]);
        $pdo->prepare("UPDATE persons SET flow_temp = NULL, conversation_state = 'AWAITING_OWNERSHIP_CHOICE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "برای مدارک خودرو، کدام یک را در اختیار دارید؟", $bot_token, kb(kb_grid(['سند مالکیت','کارت ماشین (رو و پشت)'], 2)));
    }
}
// ---- «پلاک‌های من» - مشاهده/افزودن/حذف مستقل از منوی اصلی ----
function show_my_vehicles($pdo, $chat_id, $person, $bot_token) {
    $stmt = $pdo->prepare("SELECT id, plate FROM person_vehicles WHERE person_id = ? ORDER BY created_at DESC");
    $stmt->execute([$person['id']]);
    $vehicles = $stmt->fetchAll();
    if ($vehicles) {
        $rows = [];
        foreach ($vehicles as $v) {
            $rows[] = [['text' => $v['plate'], 'callback_data' => 'vh:noop'], ['text' => '🗑 حذف', 'callback_data' => "vh:del:{$v['id']}"]];
        }
        bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => "🚙 پلاک‌های من:", 'reply_markup' => ['inline_keyboard' => $rows]], $bot_token);
    }
    send_msg($chat_id, $vehicles ? "برای افزودن خودروی جدید:" : "شما هنوز خودرویی ثبت نکرده‌اید. برای افزودن:", $bot_token, kb([['➕ افزودن پلاک جدید'], [BTN_HOME]]));
    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_MY_VEHICLES_MENU' WHERE id = ?")->execute([$person['id']]);
}

function liability_prompt_text() {
    $d = coverage_descriptions_fa();
    return "💰 سقف تعهد مالی بیمه شخص ثالث را انتخاب کنید:\n\n" .
        "• *تعهد مالی*: {$d['مالی']}\n" .
        "• *تعهد جانی*: {$d['جانی']}\n" .
        "• *حوادث راننده*: {$d['حوادث راننده']}\n\n" .
        "مبلغ موردنظر برای تعهد مالی را از لیست زیر انتخاب کنید (تومان):";
}
function liability_kb() {
    $rows = []; $r = [];
    foreach (liability_limit_options() as $a) {
        $toman = $a / 10;
        $r[] = number_format($toman);
        if (count($r) == 2) { $rows[] = $r; $r = []; }
    }
    if ($r) $rows[] = $r;
    return kb($rows);
}

function start_coverage_selection($pdo, $chat_id, $person, $bot_token) {
    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_COVERAGE_SELECT', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
    send_msg($chat_id, coverage_text(), $bot_token, kb_remove());
    bale_api('sendMessage', ['chat_id' => $chat_id, 'text' => "پوشش‌های موردنظر را با دکمه‌های زیر انتخاب کنید:", 'reply_markup' => coverage_kb([])], $bot_token);
}


// =====================================================================
//  بازدید سلامت خودرو - قوانین، شروع، مراحل، پایان
// =====================================================================
function health_rules_text() {
    $totalMin = round(health_total_seconds() / 60, 1);
    return "📋 قوانین بازدید سلامت خودرو:\n\n" .
        "• بازدید شامل ۱۴ مرحله عکس‌برداری است (۱۲ مرحله اجباری و ۲ مرحله اختیاری).\n" .
        "• برای هر مرحله ۱.۵ دقیقه فرصت دارید؛ در صورت تأخیر، بازدید باطل و باید از نو شروع شود.\n" .
        "• کل بازدید باید ظرف {$totalMin} دقیقه تمام شود، در غیر این صورت باطل می‌شود.\n" .
        "• برای هر پلاک، حداکثر ۲ بار در طول روز می‌توانید تلاش کنید.\n" .
        "• لطفاً گوشی را به‌صورت افقی (خوابیده) نگه دارید تا کل خودرو در کادر عکس جا شود.\n" .
        "• لطفاً از قبل در محیطی با نور کافی و دسترسی به خودرو آماده باشید.\n\n" .
        "برای شروع، دکمه‌ی زیر را بزنید:";
}

function get_case_row($pdo, $caseId) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    return $stmt->fetch();
}

function start_health_attempt($pdo, $chat_id, $person, $bot_token) {
    $caseId = $person['active_case_id'];
    $case = get_case_row($pdo, $caseId);
    $plate = $case['plate'] ?? null;
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_attempts WHERE plate = ? AND attempt_date = ? AND status != 'EXPIRED'");
    $stmt->execute([$plate, $today]);
    if (intval($stmt->fetchColumn()) >= HEALTH_MAX_ATTEMPTS_PER_DAY) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "❌ شما امروز ۲ بار برای این پلاک تلاش کرده‌اید. لطفاً فردا دوباره امتحان کنید.", $bot_token, main_menu_kb($pdo, $chat_id));
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO health_attempts (case_id, plate, attempt_date, status) VALUES (?, ?, ?, 'IN_PROGRESS')");
    $stmt->execute([$caseId, $plate, $today]);
    $attemptId = $pdo->lastInsertId();

    $now = time();
    $ft = ['health_case_id' => $caseId, 'attempt_id' => $attemptId, 'step' => '1',
           'step_started_at' => $now, 'total_started_at' => $now, 'photos' => []];
    $pdo->prepare("UPDATE persons SET conversation_state = 'SENDING_HEALTH_PHOTOS', flow_temp = ? WHERE id = ?")
        ->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);

    ask_health_step($pdo, $chat_id, $person['id'], $bot_token);
}

function cancel_health_attempt($pdo, $person, $status) {
    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
    if (!empty($ft['attempt_id'])) {
        $pdo->prepare("UPDATE health_attempts SET status = ? WHERE id = ?")->execute([$status, $ft['attempt_id']]);
    }
    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
}

function ask_health_step($pdo, $chat_id, $personId, $bot_token) {
    $stmt = $pdo->prepare("SELECT flow_temp FROM persons WHERE id = ?");
    $stmt->execute([$personId]);
    $ft = json_decode($stmt->fetchColumn() ?: '{}', true) ?: [];
    $steps = health_inspection_steps();
    $stepKey = $ft['step'];
    $step = $steps[$stepKey];

    $ft['step_started_at'] = time();
    $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $personId]);

    $reqText = $step['required'] ? '(اجباری)' : '(اختیاری)';
    $multiNote = $step['multi'] ? "\nمی‌توانید چند عکس پشت‌سرهم بفرستید؛ در پایان «پایان ارسال عکس‌های این مرحله» را بزنید." : '';
    $text = "📸 مرحله {$stepKey} از ۱۴ {$reqText}\n\n" . $step['label'] . " را عکس بگیرید و ارسال کنید.{$multiNote}\n\n⏱ فرصت شما برای این مرحله: ۱ دقیقه";

    $rows = [];
    if (!$step['required']) $rows[] = ['⏭ رد این مرحله (اختیاری)'];
    if ($step['multi']) $rows[] = ['✅ پایان ارسال عکس‌های این مرحله'];
    $rows[] = ['❌ انصراف از بازدید'];

    // اگر عکسِ راهنمای این مرحله در پوشه‌ی Image (روت سایت) موجود باشد، همراه توضیح ارسال می‌شود
    $guideImage = find_health_guide_image($stepKey);
    if ($guideImage) {
        $ch = curl_init("https://tapi.bale.ai/bot" . $bot_token . "/sendPhoto");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chat_id, 'photo' => new CURLFile($guideImage), 'caption' => $text, 'reply_markup' => json_encode(kb($rows))]);
        curl_exec($ch); curl_close($ch);
    } else {
        send_msg($chat_id, $text, $bot_token, kb($rows));
    }
}

// (find_health_guide_image اکنون در _case_helpers.php مشترک تعریف شده تا وب‌اپ هم از آن استفاده کند)

function health_check_timeouts($ft) {
    $now = time();
    if (($now - $ft['step_started_at']) > HEALTH_STEP_SECONDS) return 'step';
    if (($now - $ft['total_started_at']) > health_total_seconds()) return 'total';
    return null;
}

function health_advance_step($stepKey) {
    $steps = array_keys(health_inspection_steps());
    $idx = array_search($stepKey, $steps);
    return $steps[$idx + 1] ?? null;
}

function handle_health_photo($pdo, $message, $chat_id, $person, $bot_token) {
    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
    $timeoutType = health_check_timeouts($ft);
    if ($timeoutType) {
        cancel_health_attempt($pdo, $person, 'EXPIRED');
        $reason = $timeoutType === 'step' ? 'زمان این مرحله (۱ دقیقه) به پایان رسید' : 'زمان کل بازدید (۱۵ دقیقه) به پایان رسید';
        send_msg($chat_id, "⏱ {$reason} و بازدید باطل شد.\nلطفاً دوباره از «بازدید سلامت خودرو» شروع کنید.", $bot_token, main_menu_kb($pdo, $chat_id));
        return;
    }

    $caseId = $ft['health_case_id'];
    $stepKey = $ft['step'];
    $steps = health_inspection_steps();
    $step = $steps[$stepKey];

    $case = get_case_row($pdo, $ft['health_case_id']);
    $relativePath = save_incoming_case_file($message, $bot_token, $case['insured_national_id'], $case['plate']);
    if (!$relativePath) { send_msg($chat_id, "لطفاً یک عکس معتبر ارسال کنید.", $bot_token); return; }

    if ($step['multi']) {
        $ft['photos'][$stepKey][] = $relativePath;
        $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
        $count = count($ft['photos'][$stepKey]);
        send_msg($chat_id, "✅ عکس {$count} این مرحله دریافت شد. عکس بعدی را بفرستید یا «پایان ارسال عکس‌های این مرحله» را بزنید.", $bot_token);
        return;
    }

    $ft['photos'][$stepKey] = $relativePath;
    $next = health_advance_step($stepKey);
    if ($next) {
        $ft['step'] = $next;
        $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
        ask_health_step($pdo, $chat_id, $person['id'], $bot_token);
    } else {
        finalize_health_inspection($pdo, $chat_id, $person, $ft, $bot_token);
    }
}

function handle_health_skip($pdo, $chat_id, $person, $bot_token) {
    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
    $stepKey = $ft['step'];
    $next = health_advance_step($stepKey);
    if ($next) {
        $ft['step'] = $next;
        $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
        ask_health_step($pdo, $chat_id, $person['id'], $bot_token);
    } else {
        finalize_health_inspection($pdo, $chat_id, $person, $ft, $bot_token);
    }
}

function handle_health_multi_done($pdo, $chat_id, $person, $bot_token) {
    $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
    $stepKey = $ft['step'];
    if (empty($ft['photos'][$stepKey])) $ft['photos'][$stepKey] = []; // اختیاری بود و چیزی نفرستاد
    $next = health_advance_step($stepKey);
    if ($next) {
        $ft['step'] = $next;
        $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
        ask_health_step($pdo, $chat_id, $person['id'], $bot_token);
    } else {
        finalize_health_inspection($pdo, $chat_id, $person, $ft, $bot_token);
    }
}

function finalize_health_inspection($pdo, $chat_id, $person, $ft, $bot_token) {
    $caseId = $ft['health_case_id'];
    $case = get_case_row($pdo, $caseId);
    $siteRoot = dirname(__DIR__);
    $caseFolder = resolve_case_folder($pdo, $siteRoot, $case);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_inspections WHERE case_id = ?");
    $stmt->execute([$caseId]);
    $num = intval($stmt->fetchColumn()) + 1;

    // تا زمان تایید کارشناس، در یک پوشه‌ی موقت (بدون تاریخِ تاییدِ نهایی) نگه می‌داریم؛
    // بعد از تایید در پنل، به نامِ رسمیِ «(تاریخ تایید)(عکس‌های بازدید خودرو) پلاک» تغییر نام می‌یابد
    $pendingFolder = $caseFolder . '/بازدید سلامت (در انتظار تایید) ' . $num;
    if (!file_exists($pendingFolder)) mkdir($pendingFolder, 0777, true);

    $steps = health_inspection_steps();
    $finalPhotos = [];
    $tempDirsTouched = [];
    foreach ($ft['photos'] as $stepKey => $val) {
        $paths = is_array($val) ? $val : [$val];
        $stepLabel = $steps[$stepKey]['label'] ?? $stepKey;
        $subIndex = 1;
        foreach ($paths as $tempRelPath) {
            if (!$tempRelPath) continue;
            $absTemp = $siteRoot . '/' . $tempRelPath;
            $tempDirsTouched[] = dirname($absTemp);
            $ext = pathinfo($absTemp, PATHINFO_EXTENSION) ?: 'jpg';
            $isMulti = count($paths) > 1;
            $filename = health_step_filename($stepLabel, $case['plate'], $ext, $isMulti ? $subIndex : null);
            $destAbs = unique_dest_path($pendingFolder . '/' . $filename);
            if (file_exists($absTemp)) @rename($absTemp, $destAbs);
            $finalPhotos[$isMulti ? "{$stepKey}-{$subIndex}" : $stepKey] = ltrim(str_replace($siteRoot, '', $destAbs), '/');
            $subIndex++;
        }
    }
    foreach (array_unique($tempDirsTouched) as $d) cleanup_empty_dirs_upward($d, temp_archive_root($siteRoot));

    $pdo->prepare("INSERT INTO health_inspections (case_id, inspection_number, photos, photos_folder_path, status) VALUES (?, ?, ?, ?, 'PENDING')")
        ->execute([$caseId, $num, json_encode($finalPhotos, JSON_UNESCAPED_UNICODE), $pendingFolder]);
    $pdo->prepare("UPDATE health_attempts SET status = 'COMPLETED' WHERE id = ?")->execute([$ft['attempt_id']]);
    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);

    send_msg($chat_id, "🎉 بازدید سلامت شماره {$num} با موفقیت ثبت شد و در انتظار بررسی کارشناس است.", $bot_token, main_menu_kb($pdo, $chat_id));
    notify_admin($pdo, "🚗 بازدید سلامت جدید ثبت شد.\nپلاک: " . ($case['plate'] ?: '-') . "\nشماره بازدید: {$num}", $bot_token);
}

function resume_case_flow($pdo, $chat_id, $person, $case, $bot_token) {
    if (!$case['insured_relationship']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIONSHIP' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "این بیمه‌نامه برای چه کسی صادر می‌شود؟", $bot_token, kb(kb_grid(relationship_options(), 2)));
    } elseif ($case['insured_relationship'] !== 'خودم' && !$case['insured_name']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIVE_NAME' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "نام و نام‌خانوادگی بیمه‌گذار ({$case['insured_relationship']}) را وارد کنید:", $bot_token, kb_remove());
    } elseif ($case['insured_relationship'] !== 'خودم' && !$case['insured_national_id']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIVE_NID' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "کد ملی بیمه‌گذار را وارد کنید:", $bot_token);
    } elseif (!$case['insured_birth_date']) {
        goto_profile_reuse_step($pdo, $chat_id, $person, $bot_token);
    } elseif (!$case['insured_address']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_ADDRESS' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "آدرس محل سکونت " . get_case_addressee($pdo, $case['id']) . " را وارد کنید:", $bot_token);
    } elseif (!$case['insured_phone']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PHONE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "شماره موبایل " . get_case_addressee($pdo, $case['id']) . " را وارد کنید (مثال: 09123456789):", $bot_token);
    } elseif (!$case['insured_postal_code']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_POSTAL_CODE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "کد پستی ثبت‌شده در سامانه املاک و اسکان را وارد کنید (۱۰ رقم).", $bot_token);
    } elseif (!$case['plate']) {
        start_plate_builder($pdo, $chat_id, $person, $bot_token);
    } elseif (!$case['ownership_choice']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_OWNERSHIP_CHOICE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "برای مدارک خودرو، کدام یک را در اختیار دارید؟", $bot_token, kb(kb_grid(['سند مالکیت', 'کارت ماشین (رو و پشت)'], 2)));
    } elseif ($case['insurance_type'] === 'THIRDPARTY' && !$case['liability_limit']) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_LIABILITY_LIMIT' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, liability_prompt_text(), $bot_token, liability_kb());
    } elseif ($case['insurance_type'] === 'BODY' && $case['prev_body_insurance'] === null) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PREV_BODY' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "آیا پیش‌تر بیمه بدنه داشته‌اید؟", $bot_token, kb([['بله'], ['خیر']]));
    } elseif ($case['insurance_type'] === 'BODY' && !$case['selected_coverages']) {
        start_coverage_selection($pdo, $chat_id, $person, $bot_token);
    } elseif ($case['insurance_type'] === 'BODY' && $case['estimated_car_value'] === null) {
        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_CAR_VALUE' WHERE id = ?")->execute([$person['id']]);
        send_msg($chat_id, "ارزش حدودی خودرو خود را به تومان وارد کنید (اگر نمی‌دانید، دکمه زیر را بزنید):", $bot_token, kb([['🧮 محاسبه خودکار توسط کارشناس']]));
    } else {
        start_docs_collection($pdo, $chat_id, $person, $bot_token);
    }
}

function launch_health_webapp($pdo, $chat_id, $person, $mode, $caseId, $freePlate, $bot_token) {
    $siteUrl = get_site_url($pdo);
    if (!$siteUrl) {
        send_msg($chat_id, "⚠️ آدرس سایت هنوز در تنظیمات پنل ثبت نشده؛ لطفاً به کارشناس اطلاع دهید.", $bot_token, main_menu_kb($pdo, $chat_id));
        return;
    }
    $token = create_webapp_session($pdo, $person['id'], $caseId, $mode, $freePlate);
    $url = $siteUrl . '/webapp/bazdid/index_bazdid.html?t=' . $token;

    bale_api('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "🚗 بازدید سلامت خودرو آماده است.\nدکمه‌ی زیر را بزنید تا صفحه‌ی بازدید باز شود:",
        'reply_markup' => ['inline_keyboard' => [[['text' => '📸 شروع بازدید سلامت', 'web_app' => ['url' => $url]]]]],
    ], $bot_token);
}

function go_back_to_relationship($pdo, $chat_id, $person, $bot_token) {
    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIONSHIP' WHERE id = ?")->execute([$person['id']]);
    send_msg($chat_id, "این بیمه‌نامه برای چه کسی صادر می‌شود؟", $bot_token, kb(kb_grid(relationship_options(), 2)));
}
function go_home($pdo, $chat_id, $person, $bot_token) {
    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', active_case_id = NULL, flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
    send_msg($chat_id, "به منوی اصلی بازگشتید. اطلاعات و مدارک قبلی شما ذخیره شده و می‌توانید بعداً ادامه دهید.", $bot_token, main_menu_kb($pdo, $chat_id));
}
function kb_with_nav($rows) {
    $rows[] = [BTN_BACK, BTN_HOME];
    return kb($rows);
}

function go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text) {
    if ($text === BTN_HOME) { go_home($pdo, $chat_id, $person, $bot_token); return true; }
    if ($text === BTN_BACK) { go_back_to_relationship($pdo, $chat_id, $person, $bot_token); return true; }
    return false;
}

function handle_private_message($pdo, $message, $chat_id, $incoming_message_id, $text,
                                  $sender_user_id, $sender_name, $sender_username, $bot_token) {

    $contact = $message['contact'] ?? null;

    // ---- شخص شناسایی‌شده؟ ----
    $stmt = $pdo->prepare("SELECT * FROM persons WHERE bale_chat_id = ?");
    $stmt->execute([$chat_id]);
    $person = $stmt->fetch();

    // =================================================================
    //  حالت پشتیبانی: در هر مرحله‌ای قابل ورود/خروج است
    // =================================================================
    $currentState = $person ? $person['conversation_state'] : null;

    // ---- دکمه‌ی اعتباری (جایگزین دکمه‌ی اشتراک‌گذاری شماره تماس) - در هر حالتی قابل تشخیص است ----
    if ($text === '🎨 طراحی و توسعه: گروه برگه (MMBehzadi) - تمامی حقوق محفوظ می‌باشد') {
        send_msg($chat_id, "کاربر عزیز، برای استفاده از ربات از دکمه‌های زیر استفاده کنید 👇", $bot_token, $person ? main_menu_kb($pdo, $chat_id) : null);
        return;
    }

    if ($currentState === 'IN_SUPPORT_CHAT') {
        if ($text === BTN_END_CHAT || $text === '/done') {
            // بازگشت به وضعیت قبلی (دوباره محاسبه می‌شود، نه ذخیره‌شده)
            if ($person) {
                // اگر پرونده دارد MAIN_MENU وگرنه LIMITED_MENU
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM introductions WHERE person_id = ?");
                $stmt->execute([$person['id']]);
                $hasIntro = $stmt->fetchColumn() > 0;
                $newState = $hasIntro ? 'MAIN_MENU' : 'LIMITED_MENU';
                $pdo->prepare("UPDATE persons SET conversation_state = ? WHERE id = ?")->execute([$newState, $person['id']]);
                send_msg($chat_id, "گفتگو با کارشناس پایان یافت.", $bot_token, $newState === 'MAIN_MENU' ? main_menu_kb($pdo, $chat_id) : limited_menu_kb());
            } else {
                send_msg($chat_id, "گفتگو با کارشناس پایان یافت. لطفاً /start را بزنید.", $bot_token, kb_remove());
            }
            return;
        }
        $ticket_id = find_open_ticket($pdo, $chat_id);
        if ($ticket_id) {
            log_ticket_message($pdo, $ticket_id, $text ?: '[فایل/رسانه]', null, $incoming_message_id);
            send_msg($chat_id, "✅ پیام شما برای کارشناس ارسال شد.", $bot_token, support_mode_kb());
        }
        return;
    }

    if ($text === BTN_SUPPORT) {
        $ticket_id = find_open_ticket($pdo, $chat_id);
        if (!$ticket_id) {
            $ticket_id = create_ticket($pdo, $chat_id, $person['id'] ?? null, $sender_name, $sender_username);
        }
        if ($person) {
            $pdo->prepare("UPDATE persons SET conversation_state = 'IN_SUPPORT_CHAT' WHERE id = ?")->execute([$person['id']]);
        }
        send_msg($chat_id, "💬 به کارشناس پشتیبانی وصل شدید.\nپیام خود را بنویسید؛ برای پایان گفتگو دکمه‌ی زیر را بزنید.", $bot_token, support_mode_kb());
        return;
    }

    // =================================================================
    //  کارشناس یک فیلد خاص را ایراددار اعلام کرده و منتظر مقدار اصلاح‌شده‌ایم
    // =================================================================
    if ($currentState === 'FIXING_FIELD' && $person) {
        $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
        $fixFields = $ft['fix_fields'] ?? [];
        $fixLabels = $ft['fix_labels'] ?? [];
        $idx = $ft['fix_index'] ?? 0;
        $allowedFields = ['plate', 'insured_name', 'insured_national_id', 'insured_birth_date', 'insured_address', 'insured_phone', 'insured_postal_code'];

        $field = $fixFields[$idx] ?? null;
        $label = $fixLabels[$idx] ?? 'اطلاعات';
        if (!in_array($field, $allowedFields, true)) $field = null;
        $valid = true; $errorMsg = '';

        if ($field === 'insured_postal_code' && !preg_match('/^\d{10}$/', $text)) { $valid = false; $errorMsg = 'کد پستی باید دقیقاً ۱۰ رقم باشد.'; }
        elseif ($field === 'insured_phone' && !preg_match('/^09\d{9}$/', $text)) { $valid = false; $errorMsg = 'شماره موبایل باید ۱۱ رقم و با 09 شروع شود.'; }
        elseif ($field === 'insured_national_id' && !preg_match('/^\d{10}$/', $text)) { $valid = false; $errorMsg = 'کد ملی باید دقیقاً ۱۰ رقم باشد.'; }
        elseif ($field === 'insured_birth_date' && !preg_match('/^1[34]\d{2}\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/', $text)) { $valid = false; $errorMsg = 'فرمت تاریخ باید مثل 1405/02/03 باشد.'; }
        elseif ($field && $text === '') { $valid = false; $errorMsg = 'مقدار نمی‌تواند خالی باشد.'; }

        if (!$valid) {
            send_msg($chat_id, "❌ {$errorMsg}\nلطفاً {$label} صحیح را دوباره ارسال کنید:", $bot_token);
            return;
        }

        if ($field) {
            $pdo->prepare("UPDATE policy_cases SET {$field} = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
        }

        $nextIdx = $idx + 1;
        if ($nextIdx < count($fixFields)) {
            $ft['fix_index'] = $nextIdx;
            $pdo->prepare("UPDATE persons SET flow_temp = ? WHERE id = ?")->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
            send_msg($chat_id, "✅ {$label} ثبت شد.\nحالا مقدار «{$fixLabels[$nextIdx]}» را ارسال کنید:", $bot_token);
        } else {
            $pdo->prepare("UPDATE policy_cases SET status = 'DOCS_REVIEW' WHERE id = ?")->execute([$person['active_case_id']]);
            $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
            send_msg($chat_id, "✅ همه‌ی موارد با موفقیت اصلاح شد و دوباره در انتظار بررسی کارشناس قرار گرفت.", $bot_token, main_menu_kb($pdo, $chat_id));
        }
        return;
    }

    // =================================================================
    //  در حال جمع‌آوری مدارک یک پرونده‌ی صدور
    // =================================================================
    if ($currentState === 'COLLECTING_DOCS' && $person) {
        if (isset($message['document']) || isset($message['photo'])) {
            handle_case_document_upload($pdo, $message, $chat_id, $person, $bot_token);
        } else {
            send_msg($chat_id, "لطفاً موردی که درخواست شده را به‌صورت عکس یا فایل ارسال کنید.", $bot_token);
        }
        return;
    }

    // =================================================================
    //  بازدید سلامت خودرو - نمایش قوانین قبل از شروع
    // =================================================================
    if ($currentState === 'AWAITING_HEALTH_RULES' && $person) {
        if ($text === '✅ شروع بازدید') {
            start_health_attempt($pdo, $chat_id, $person, $bot_token);
        } elseif ($text === BTN_BACK) {
            $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
            send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
        } else {
            send_msg($chat_id, "لطفاً «شروع بازدید» را بزنید یا با «بازگشت» انصراف دهید.", $bot_token, kb([['✅ شروع بازدید'], [BTN_BACK]]));
        }
        return;
    }

    // =================================================================
    //  بازدید سلامت خودرو - در حال دریافت عکس‌های مرحله‌به‌مرحله (۱۴ مرحله)
    // =================================================================
    if ($currentState === 'SENDING_HEALTH_PHOTOS' && $person) {
        if ($text === '❌ انصراف از بازدید') {
            cancel_health_attempt($pdo, $person, 'CANCELLED');
            send_msg($chat_id, "بازدید لغو شد. هر زمان آماده بودید دوباره از «بازدید سلامت خودرو» شروع کنید.", $bot_token, main_menu_kb($pdo, $chat_id));
        } elseif ($text === '⏭ رد این مرحله (اختیاری)') {
            handle_health_skip($pdo, $chat_id, $person, $bot_token);
        } elseif ($text === '✅ پایان ارسال عکس‌های این مرحله') {
            handle_health_multi_done($pdo, $chat_id, $person, $bot_token);
        } elseif (isset($message['photo']) || isset($message['document'])) {
            handle_health_photo($pdo, $message, $chat_id, $person, $bot_token);
        } else {
            send_msg($chat_id, "لطفاً عکس این مرحله را ارسال کنید.", $bot_token);
        }
        return;
    }

    // =================================================================
    //  کاربر قبلاً شناسایی شده -> بر اساس conversation_state پیش برو
    // =================================================================
    if ($person) {
        // برای اینکه گفتگوی دوطرفه در تب «کاربران» پنل کامل دیده شود، هر پیام
        // متنی کاربر شناسایی‌شده (چه دکمه منو، چه متن آزاد) را لاگ می‌کنیم.
        if ($text !== '') {
            $pdo->prepare("INSERT INTO messages (person_id, sender_type, message_text) VALUES (?, 'CUSTOMER', ?)")
                ->execute([$person['id'], $text]);
        }

        switch ($currentState) {

            case 'MAIN_MENU':
                if ($text === BTN_ORDER) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_INSURANCE_CHOICE' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "نوع بیمه‌نامه مورد نظر خود را انتخاب کنید:", $bot_token, insurance_choice_kb());
                } elseif ($text === BTN_HEALTH) {
                    $stmt = $pdo->prepare("SELECT id, unique_code, plate FROM policy_cases WHERE person_id = ? AND insurance_type = 'BODY' AND status IN ('DOCS_REVIEW','ISSUING','ISSUED') ORDER BY created_at DESC");
                    $stmt->execute([$person['id']]);
                    $bodyCases = $stmt->fetchAll();
                    $rows = array_map(fn($c) => [($c['plate'] ?: $c['unique_code']) . ' (' . $c['unique_code'] . ')'], $bodyCases);
                    $rows[] = ['🆕 بازدید آزاد (پلاک دیگر)'];
                    $rows[] = [BTN_BACK];
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_HEALTH_CASE_SELECT' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "بازدید سلامت برای کدام خودرو ثبت می‌شود؟", $bot_token, kb($rows));
                } elseif ($text === BTN_MY_POLICIES) {
                    list_my_policies($pdo, $chat_id, $person, $bot_token);
                } elseif ($text === BTN_MY_VEHICLES) {
                    show_my_vehicles($pdo, $chat_id, $person, $bot_token);
                } elseif ($text === BTN_PREMIUM_INQUIRY) {
                    send_msg($chat_id, "💰 استعلام حق بیمه به‌زودی فعال می‌شود.", $bot_token, main_menu_kb($pdo, $chat_id));
                } elseif ($text === BTN_HELP) {
                    send_msg($chat_id, "ℹ️ راهنما:\n📝 ثبت درخواست بیمه: شروع فرآیند صدور بیمه‌نامه ثالث یا بدنه\n🚗 بازدید سلامت خودرو: ثبت بازدید ۱۴مرحله‌ای برای بیمه بدنه\n📁 بیمه‌نامه‌های من: مشاهده وضعیت و دریافت فایل بیمه‌نامه‌های صادرشده\n💬 ارتباط با کارشناس: گفتگوی مستقیم با پشتیبانی", $bot_token, main_menu_kb($pdo, $chat_id));
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌های منو را انتخاب کنید:", $bot_token, main_menu_kb($pdo, $chat_id));
                }
                break;

            case 'AWAITING_RESUME_CHOICE':
                if ($text === '▶️ ادامه بده') {
                    $case = get_case_row($pdo, $person['active_case_id']);
                    resume_case_flow($pdo, $chat_id, $person, $case, $bot_token);
                } elseif ($text === '🗑 حذف و شروع دوباره') {
                    $pdo->prepare("DELETE FROM case_documents WHERE case_id = ?")->execute([$person['active_case_id']]);
                    $pdo->prepare("DELETE FROM policy_cases WHERE id = ?")->execute([$person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_INSURANCE_CHOICE', active_case_id = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "درخواست قبلی حذف شد. نوع بیمه‌نامه‌ی جدید را انتخاب کنید:", $bot_token, insurance_choice_kb());
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌ها را انتخاب کنید:", $bot_token, kb([['▶️ ادامه بده'], ['🗑 حذف و شروع دوباره']]));
                }
                break;

            case 'AWAITING_INSURANCE_CHOICE':
                if ($text === BTN_THIRDPARTY || $text === BTN_BODY) {
                    $enumType = ($text === BTN_BODY) ? 'BODY' : 'THIRDPARTY';

                    // آیا درخواست ناتمامِ قبلی از همین نوع وجود دارد؟
                    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE person_id = ? AND insurance_type = ? AND status = 'REGISTERED' ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$person['id'], $enumType]);
                    $incomplete = $stmt->fetch();
                    if ($incomplete) {
                        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RESUME_CHOICE', active_case_id = ? WHERE id = ?")->execute([$incomplete['id'], $person['id']]);
                        send_msg($chat_id, "شما یک درخواست " . insurance_type_fa($enumType) . " ناتمام دارید. می‌خواهید از همان‌جا ادامه دهید یا حذفش کرده و از نو شروع کنید؟", $bot_token, kb([['▶️ ادامه بده'], ['🗑 حذف و شروع دوباره']]));
                        break;
                    }

                    $stmt = $pdo->prepare("SELECT id, max_quota, used_quota FROM introductions WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$person['id']]);
                    $intro = $stmt->fetch();

                    if (!$intro || intval($intro['used_quota']) >= intval($intro['max_quota'] ?: 4)) {
                        $pdo->prepare("UPDATE persons SET conversation_state = 'LIMITED_MENU' WHERE id = ?")->execute([$person['id']]);
                        send_msg($chat_id, "سقف صدور بیمه‌نامه با معرفی‌نامه فعلی شما تکمیل شده است.", $bot_token, limited_menu_kb());
                        break;
                    }

                    $stmt = $pdo->prepare("INSERT INTO policy_cases (introduction_id, person_id, insurance_type, unique_code) VALUES (?, ?, ?, '')");
                    $stmt->execute([$intro['id'], $person['id'], $enumType]);
                    $caseId = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE policy_cases SET unique_code = ? WHERE id = ?")->execute([generate_case_unique_code($caseId), $caseId]);

                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIONSHIP', active_case_id = ? WHERE id = ?")->execute([$caseId, $person['id']]);
                    send_msg($chat_id, "این بیمه‌نامه برای چه کسی صادر می‌شود؟", $bot_token, kb(kb_grid(relationship_options(), 2)));
                } elseif ($text === BTN_BACK) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } else {
                    send_msg($chat_id, "لطفاً «ثالث» یا «بدنه» را انتخاب کنید:", $bot_token, insurance_choice_kb());
                }
                break;

            case 'AWAITING_RELATIONSHIP':
                if (in_array($text, relationship_options())) {
                    $pdo->prepare("UPDATE policy_cases SET insured_relationship = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    if ($text === 'خودم') {
                        $pdo->prepare("UPDATE policy_cases SET insured_name = ?, insured_national_id = ? WHERE id = ?")
                            ->execute([$person['full_name'], $person['national_code'], $person['active_case_id']]);
                        goto_profile_reuse_step($pdo, $chat_id, $person, $bot_token);
                    } else {
                        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIVE_NAME' WHERE id = ?")->execute([$person['id']]);
                        send_msg($chat_id, "نام و نام‌خانوادگی بیمه‌گذار ({$text}) را وارد کنید:", $bot_token, kb_remove());
                    }
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $bot_token, kb(kb_grid(relationship_options(), 2)));
                }
                break;

            case 'AWAITING_RELATIVE_NAME':
                if ($text !== '') {
                    $pdo->prepare("UPDATE policy_cases SET insured_name = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_RELATIVE_NID' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "کد ملی بیمه‌گذار را وارد کنید:", $bot_token);
                } else {
                    send_msg($chat_id, "لطفاً نام و نام‌خانوادگی را به‌صورت متن ارسال کنید:", $bot_token);
                }
                break;

            case 'AWAITING_RELATIVE_NID':
                if (preg_match('/^\d{8,10}$/', $text)) {
                    $pdo->prepare("UPDATE policy_cases SET insured_national_id = ? WHERE id = ?")->execute([str_pad($text, 10, '0', STR_PAD_LEFT), $person['active_case_id']]);
                    goto_profile_reuse_step($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "کد ملی نامعتبر است. لطفاً فقط عدد وارد کنید:", $bot_token);
                }
                break;

            case 'AWAITING_PROFILE_REUSE':
                if ($text === BTN_USE_SAVED) {
                    $case = get_case_row($pdo, $person['active_case_id']);
                    $allSaved = json_decode($person['saved_profile'] ?: '{}', true) ?: [];
                    $saved = $allSaved[get_profile_key($case)] ?? [];
                    $pdo->prepare("UPDATE policy_cases SET insured_birth_date = ?, insured_address = ?, insured_phone = ?, insured_postal_code = ? WHERE id = ?")
                        ->execute([$saved['birth_date'] ?? null, $saved['address'] ?? null, $saved['phone'] ?? null, $saved['postal_code'] ?? null, $person['active_case_id']]);
                    start_plate_builder($pdo, $chat_id, $person, $bot_token);
                } elseif ($text === BTN_ENTER_NEW) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_BIRTH_DATE' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "تاریخ تولد " . get_case_addressee($pdo, $person['active_case_id']) . " را با فرمت 1405/02/03 وارد کنید:", $bot_token, kb_remove());
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌ها را انتخاب کنید:", $bot_token, kb([[BTN_USE_SAVED], [BTN_ENTER_NEW]]));
                }
                break;

            case 'AWAITING_BIRTH_DATE':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if (preg_match('/^1[34]\d{2}\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/', $text)) {
                    $pdo->prepare("UPDATE policy_cases SET insured_birth_date = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_ADDRESS' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "آدرس محل سکونت " . get_case_addressee($pdo, $person['active_case_id']) . " را وارد کنید:", $bot_token, kb_with_nav([]));
                } else {
                    send_msg($chat_id, "❌ فرمت تاریخ تولد نامعتبر است. لطفاً دقیقاً به فرمت 1405/02/03 وارد کنید:", $bot_token, kb_with_nav([]));
                }
                break;

            case 'AWAITING_ADDRESS':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if (mb_strlen($text) >= 10) {
                    $pdo->prepare("UPDATE policy_cases SET insured_address = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PHONE' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "شماره موبایل " . get_case_addressee($pdo, $person['active_case_id']) . " را وارد کنید (مثال: 09123456789):", $bot_token, kb_with_nav([]));
                } else {
                    send_msg($chat_id, "❌ آدرس وارد‌شده خیلی کوتاه است. لطفاً آدرس کامل را وارد کنید:", $bot_token, kb_with_nav([]));
                }
                break;

            case 'AWAITING_PHONE':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if (preg_match('/^09\d{9}$/', $text)) {
                    $pdo->prepare("UPDATE policy_cases SET insured_phone = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_POSTAL_CODE' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "کد پستی ثبت‌شده در سامانه املاک و اسکان را وارد کنید (۱۰ رقم).\n\nℹ️ راهنما: اگر کد پستی را نمی‌دانید، باید به سامانه ملی املاک و اسکان (amlak.mrud.ir) مراجعه کرده و برای این کد ملی کد پستی ثبت کنید.", $bot_token, kb_with_nav([]));
                } else {
                    send_msg($chat_id, "❌ شماره موبایل نامعتبر است. باید ۱۱ رقم باشد و با 09 شروع شود (مثال: 09123456789):", $bot_token, kb_with_nav([]));
                }
                break;

            case 'AWAITING_POSTAL_CODE':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if (preg_match('/^\d{10}$/', $text)) {
                    $pdo->prepare("UPDATE policy_cases SET insured_postal_code = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    // کش‌کردن پروفایل برای استفاده در دفعات بعد - جداگانه برای هر بیمه‌گذار (خودم/هر یک از بستگان)
                    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
                    $stmt->execute([$person['active_case_id']]);
                    $cd = $stmt->fetch();
                    $profile = ['birth_date' => $cd['insured_birth_date'], 'address' => $cd['insured_address'], 'phone' => $cd['insured_phone'], 'postal_code' => $cd['insured_postal_code']];
                    $allSaved = json_decode($person['saved_profile'] ?: '{}', true) ?: [];
                    $allSaved[get_profile_key($cd)] = $profile;
                    $pdo->prepare("UPDATE persons SET saved_profile = ? WHERE id = ?")->execute([json_encode($allSaved, JSON_UNESCAPED_UNICODE), $person['id']]);
                    start_plate_builder($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "❌ کد پستی باید دقیقاً ۱۰ رقم باشد. لطفاً دوباره وارد کنید:", $bot_token, kb_with_nav([]));
                }
                break;

            case 'AWAITING_VEHICLE_CHOICE':
                $vft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
                $purpose = $vft['purpose'] ?? 'new';
                if ($text === '🆕 خودروی جدید') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PLATE_BUILD', flow_temp = ? WHERE id = ?")
                        ->execute([json_encode(['purpose' => $purpose], JSON_UNESCAPED_UNICODE), $person['id']]);
                    ask_plate_step($pdo, $chat_id, $person['id'], $bot_token);
                } elseif ($text === BTN_HOME) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', active_case_id = NULL, flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } elseif ($text === BTN_BACK) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM person_vehicles WHERE person_id = ? AND plate = ?");
                    $stmt->execute([$person['id'], $text]);
                    if ($stmt->fetchColumn()) {
                        apply_chosen_plate($pdo, $chat_id, $person, $text, $purpose, $bot_token);
                    } else {
                        send_msg($chat_id, "لطفاً از دکمه‌های زیر انتخاب کنید:", $bot_token);
                        start_plate_builder($pdo, $chat_id, $person, $bot_token, $purpose);
                    }
                }
                break;

            case 'AWAITING_OWNERSHIP_CHOICE':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if ($text === 'سند مالکیت' || $text === 'کارت ماشین (رو و پشت)') {
                    $choice = ($text === 'سند مالکیت') ? 'سند' : 'کارت ماشین';
                    $pdo->prepare("UPDATE policy_cases SET ownership_choice = ? WHERE id = ?")->execute([$choice, $person['active_case_id']]);

                    $stmt = $pdo->prepare("SELECT insurance_type FROM policy_cases WHERE id = ?");
                    $stmt->execute([$person['active_case_id']]);
                    $insType = $stmt->fetchColumn();

                    if ($insType === 'THIRDPARTY') {
                        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PREV_THIRD' WHERE id = ?")->execute([$person['id']]);
                        send_msg($chat_id, "آیا تصویر بیمه ثالث قبل را دارید؟ (اختیاری است)", $bot_token, kb_with_nav([['دارم، ارسال می‌کنم'], ['ندارم، رد شو']]));
                    } else {
                        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PREV_BODY' WHERE id = ?")->execute([$person['id']]);
                        send_msg($chat_id, "آیا پیش‌تر بیمه بدنه داشته‌اید؟", $bot_token, kb_with_nav([['بله'], ['خیر']]));
                    }
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌ها را انتخاب کنید:", $bot_token, kb_with_nav(kb_grid(['سند مالکیت','کارت ماشین (رو و پشت)'], 2)));
                }
                break;

            case 'AWAITING_PREV_THIRD':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if ($text === 'دارم، ارسال می‌کنم') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_LIABILITY_LIMIT' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "بسیار خب، در مرحله‌ی ارسال مدارک از شما خواسته می‌شود. " . liability_prompt_text(), $bot_token, kb_with_nav(liability_kb()['keyboard']));
                } elseif ($text === 'ندارم، رد شو') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_LIABILITY_LIMIT' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, liability_prompt_text(), $bot_token, kb_with_nav(liability_kb()['keyboard']));
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌ها را انتخاب کنید:", $bot_token, kb_with_nav([['دارم، ارسال می‌کنم'], ['ندارم، رد شو']]));
                }
                break;

            case 'AWAITING_LIABILITY_LIMIT':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                $plainTomans = (int) preg_replace('/[^\d]/', '', $text);
                $chosenRial = $plainTomans * 10;
                $chosen = in_array($chosenRial, liability_limit_options()) ? $chosenRial : null;

                if ($chosen) {
                    $pdo->prepare("UPDATE policy_cases SET liability_limit = ? WHERE id = ?")->execute([$chosen, $person['active_case_id']]);
                    start_docs_collection($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "لطفاً یکی از مبالغ لیست‌شده را انتخاب کنید:", $bot_token, kb_with_nav(liability_kb()['keyboard']));
                }
                break;

            case 'AWAITING_PREV_BODY':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if ($text === 'بله') {
                    $pdo->prepare("UPDATE policy_cases SET prev_body_insurance = 'بله' WHERE id = ?")->execute([$person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_PREV_BODY_CLAIM' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "آیا از بیمه بدنه قبلی خسارتی دریافت کرده‌اید؟", $bot_token, kb_with_nav([['بله'], ['خیر']]));
                } elseif ($text === 'خیر') {
                    $pdo->prepare("UPDATE policy_cases SET prev_body_insurance = 'خیر' WHERE id = ?")->execute([$person['active_case_id']]);
                    start_coverage_selection($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "لطفاً «بله» یا «خیر» را انتخاب کنید:", $bot_token, kb_with_nav([['بله'], ['خیر']]));
                }
                break;

            case 'AWAITING_PREV_BODY_CLAIM':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if ($text === 'بله') {
                    $pdo->prepare("UPDATE policy_cases SET prev_body_claim = 'بله' WHERE id = ?")->execute([$person['active_case_id']]);
                    start_coverage_selection($pdo, $chat_id, $person, $bot_token);
                } elseif ($text === 'خیر') {
                    $pdo->prepare("UPDATE policy_cases SET prev_body_claim = 'خیر' WHERE id = ?")->execute([$person['active_case_id']]);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_NO_CLAIM_YEARS' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "چند سال متوالی بدون خسارت بوده‌اید؟ (عدد وارد کنید)", $bot_token, kb_with_nav([]));
                } else {
                    send_msg($chat_id, "لطفاً «بله» یا «خیر» را انتخاب کنید:", $bot_token, kb_with_nav([['بله'], ['خیر']]));
                }
                break;

            case 'AWAITING_NO_CLAIM_YEARS':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if (preg_match('/^\d{1,2}$/', $text)) {
                    $pdo->prepare("UPDATE policy_cases SET no_claim_years = ? WHERE id = ?")->execute([intval($text), $person['active_case_id']]);
                    start_coverage_selection($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "لطفاً تعداد سال را به‌صورت عدد وارد کنید:", $bot_token, kb_with_nav([]));
                }
                break;

            case 'AWAITING_CAR_VALUE':
                if (go_back_to_relationship_and_check($pdo, $chat_id, $person, $bot_token, $text)) break;
                if ($text === '🧮 محاسبه خودکار توسط کارشناس' || preg_match('/^[\d,]+$/', $text)) {
                    $value = ($text === '🧮 محاسبه خودکار توسط کارشناس') ? null : intval(str_replace(',', '', $text));
                    $pdo->prepare("UPDATE policy_cases SET estimated_car_value = ? WHERE id = ?")->execute([$value, $person['active_case_id']]);
                    start_docs_collection($pdo, $chat_id, $person, $bot_token);
                } else {
                    send_msg($chat_id, "لطفاً مبلغ را به عدد وارد کنید یا دکمه «محاسبه خودکار» را بزنید:", $bot_token, kb_with_nav([['🧮 محاسبه خودکار توسط کارشناس']]));
                }
                break;

            case 'AWAITING_FINAL_CONFIRM':
                if ($text === '✅ بله، ثبت نهایی شود') {
                    $pdo->prepare("UPDATE policy_cases SET status = 'DOCS_REVIEW' WHERE id = ?")->execute([$person['active_case_id']]);
                    $case = get_case_row($pdo, $person['active_case_id']);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', active_case_id = NULL, flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "🎉 درخواست شما با شناسه‌ی {$case['unique_code']} ثبت نهایی شد و به کارشناسان ارجاع شد.\nطی ساعات آینده نتیجه‌ی بررسی درخواست شما از طریق همین ربات به اطلاعتان می‌رسد.\nاز طریق «بیمه‌نامه‌های من» می‌توانید وضعیت آن را پیگیری کنید.", $bot_token, main_menu_kb($pdo, $chat_id));
                    $pdo->prepare("INSERT INTO app_notifications (person_id, title, message, type, related_case_id) VALUES (?, ?, ?, 'success', ?)")
                        ->execute([$person['id'], 'درخواست ثبت شد', "درخواست بیمه {$case['unique_code']} با موفقیت ثبت شد و در انتظار بررسی کارشناس است.", $person['active_case_id']]);
                    notify_admin($pdo, "📝 درخواست بیمه جدید ثبت شد.\nشناسه: {$case['unique_code']}\nبیمه‌گذار: {$case['insured_name']}\nنوع: " . insurance_type_fa($case['insurance_type']) . "\nپلاک: " . ($case['plate'] ?: '-'), $bot_token);
                } elseif ($text === '✏️ ویرایش اطلاعات') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_EDIT_FIELD_SELECT' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "کدام مورد را می‌خواهید ویرایش کنید؟", $bot_token, edit_field_menu_kb());
                } elseif ($text === '↩️ بازگشت به منو') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU', active_case_id = NULL, flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید. اطلاعات و مدارک شما ذخیره شده و می‌توانید بعداً ادامه دهید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌ها را انتخاب کنید:", $bot_token, kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], ['↩️ بازگشت به منو']]));
                }
                break;

            case 'AWAITING_EDIT_FIELD_SELECT':
                if ($text === BTN_BACK) {
                    $case = get_case_row($pdo, $person['active_case_id']);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_FINAL_CONFIRM' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, build_case_summary_text($case, $person) . "\n\nآیا از صحت اطلاعات بالا مطمئن هستید؟", $bot_token, kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], ['↩️ بازگشت به منو']]));
                } else {
                    $matchKey = null;
                    foreach (EDITABLE_FIELDS as $key => $f) if ($f['label'] === $text) { $matchKey = $key; break; }
                    if ($matchKey) {
                        $case = get_case_row($pdo, $person['active_case_id']);
                        $currentVal = $case[$matchKey] ?? '';
                        $ft = ['edit_field' => $matchKey];
                        $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_EDIT_VALUE', flow_temp = ? WHERE id = ?")
                            ->execute([json_encode($ft, JSON_UNESCAPED_UNICODE), $person['id']]);
                        $currentLine = $currentVal !== '' ? "مقدار فعلی: {$currentVal}\n\n" : '';
                        send_msg($chat_id, "{$currentLine}مقدار جدید برای «{$text}» را وارد کنید:", $bot_token, kb([[BTN_BACK]]));
                    } else {
                        send_msg($chat_id, "لطفاً از دکمه‌های زیر انتخاب کنید:", $bot_token, edit_field_menu_kb());
                    }
                }
                break;

            case 'AWAITING_EDIT_VALUE':
                $ft = json_decode($person['flow_temp'] ?: '{}', true) ?: [];
                $fieldKey = $ft['edit_field'] ?? null;
                $fieldInfo = EDITABLE_FIELDS[$fieldKey] ?? null;
                if ($text === BTN_BACK) {
                    $case = get_case_row($pdo, $person['active_case_id']);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_FINAL_CONFIRM', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "ویرایش انصراف داده شد.\n\n" . build_case_summary_text($case, $person) . "\n\nآیا از صحت اطلاعات بالا مطمئن هستید؟", $bot_token, kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], ['↩️ بازگشت به منو']]));
                } elseif (!$fieldInfo) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_EDIT_FIELD_SELECT' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "خطایی رخ داد. دوباره انتخاب کنید:", $bot_token, edit_field_menu_kb());
                } elseif ($fieldInfo['type'] === 'plate') {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_EDIT_FIELD_SELECT', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    start_plate_builder($pdo, $chat_id, $person, $bot_token); // بعد از ساخت پلاک به‌طور خودکار برمی‌گردد به گرید مدارک
                } elseif (!validate_field_value($fieldInfo['type'], $text)) {
                    send_msg($chat_id, "❌ مقدار وارد‌شده معتبر نیست. دوباره «{$fieldInfo['label']}» را وارد کنید:", $bot_token, kb([[BTN_BACK]]));
                } else {
                    $pdo->prepare("UPDATE policy_cases SET {$fieldKey} = ? WHERE id = ?")->execute([$text, $person['active_case_id']]);
                    $case = get_case_row($pdo, $person['active_case_id']);
                    $pdo->prepare("UPDATE persons SET conversation_state = 'AWAITING_FINAL_CONFIRM', flow_temp = NULL WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "✅ بروزرسانی شد.\n\n" . build_case_summary_text($case, $person) . "\n\nآیا از صحت اطلاعات بالا مطمئن هستید؟", $bot_token, kb([['✅ بله، ثبت نهایی شود'], ['✏️ ویرایش اطلاعات'], ['↩️ بازگشت به منو']]));
                }
                break;

            case 'AWAITING_HEALTH_CASE_SELECT':
                if ($text === BTN_BACK || $text === BTN_HOME) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } elseif ($text === '🆕 بازدید آزاد (پلاک دیگر)') {
                    // از همون دکمه‌های شیشه‌ای پلاک‌ساز استفاده می‌کنیم، دقیقاً مثل ثبت سفارش
                    start_plate_builder($pdo, $chat_id, $person, $bot_token, 'free_health');
                } else {
                    // کد یکتا را از داخل پرانتزِ متن دکمه‌ی انتخابی استخراج می‌کنیم
                    preg_match('/\(([^)]+)\)$/', $text, $m);
                    $code = $m[1] ?? $text;
                    $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE person_id = ? AND unique_code = ?");
                    $stmt->execute([$person['id'], $code]);
                    $caseId = $stmt->fetchColumn();
                    if ($caseId) {
                        launch_health_webapp($pdo, $chat_id, $person, 'case', $caseId, null, $bot_token);
                    } else {
                        send_msg($chat_id, "گزینه نامعتبر است. لطفاً از دکمه‌های زیر انتخاب کنید.", $bot_token);
                    }
                }
                break;

            case 'AWAITING_MY_VEHICLES_MENU':
                if ($text === '➕ افزودن پلاک جدید') {
                    start_plate_builder($pdo, $chat_id, $person, $bot_token, 'add_only');
                } elseif ($text === BTN_HOME) {
                    $pdo->prepare("UPDATE persons SET conversation_state = 'MAIN_MENU' WHERE id = ?")->execute([$person['id']]);
                    send_msg($chat_id, "به منوی اصلی بازگشتید.", $bot_token, main_menu_kb($pdo, $chat_id));
                } else {
                    send_msg($chat_id, "لطفاً از دکمه‌های زیر انتخاب کنید:", $bot_token, kb([['➕ افزودن پلاک جدید'], [BTN_HOME]]));
                }
                break;

            case 'LIMITED_MENU':
            default:
                if ($text === BTN_RECHECK) {
                    identify_and_route($pdo, $chat_id, $person['national_code'], $person['mobile_number'], $bot_token);
                } elseif ($text === BTN_HELP) {
                    send_msg($chat_id, "ℹ️ برای استفاده از امکانات ربات، ابتدا باید معرفی‌نامه‌ی شما توسط کارشناسان تایید شود.\nبرای پیگیری از «ارتباط با کارشناس» استفاده کنید.", $bot_token, limited_menu_kb());
                } else {
                    send_msg($chat_id, "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $bot_token, limited_menu_kb());
                }
                break;
        }
        return;
    }

    // =================================================================
    //  کاربر هنوز شناسایی نشده -> جریان bot_sessions
    // =================================================================
    $stmt = $pdo->prepare("SELECT * FROM bot_sessions WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $session = $stmt->fetch();

    if ($text === '/start' || !$session) {
        $pdo->prepare("
            INSERT INTO bot_sessions (chat_id, conversation_state, sender_name, sender_username)
            VALUES (?, 'AWAITING_NATIONAL_CODE', ?, ?)
            ON DUPLICATE KEY UPDATE conversation_state = 'AWAITING_NATIONAL_CODE', sender_name = VALUES(sender_name), sender_username = VALUES(sender_username)
        ")->execute([$chat_id, $sender_name ?: null, $sender_username]);
        send_msg($chat_id, "سلام! به سامانه بیمه اتومبیل پاسارگاد ماموت خوش آمدید. 🐘\nلطفاً کد ملی خود را وارد کنید:", $bot_token, kb_remove());
        return;
    }

    if ($session['conversation_state'] === 'AWAITING_NATIONAL_CODE') {
        if (preg_match('/^\d{8,10}$/', $text)) {
            $normalized = str_pad($text, 10, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE bot_sessions SET conversation_state = 'AWAITING_CONTACT_SHARE', temp_national_code = ? WHERE chat_id = ?")
                ->execute([$normalized, $chat_id]);
            send_msg($chat_id, "لطفاً برای تایید هویت، شماره تماس خود را با دکمه‌ی زیر به اشتراک بگذارید:", $bot_token, kb_request_contact());
        } else {
            send_msg($chat_id, "کد ملی وارد شده معتبر نیست. لطفاً فقط عدد و ۱۰ رقمی وارد کنید:", $bot_token);
        }
        return;
    }

    if ($session['conversation_state'] === 'AWAITING_CONTACT_SHARE') {
        if ($contact && (empty($contact['user_id']) || $contact['user_id'] == $sender_user_id)) {
            $mobile = $contact['phone_number'] ?? null;
            $national_code = $session['temp_national_code'];
            $pdo->prepare("DELETE FROM bot_sessions WHERE chat_id = ?")->execute([$chat_id]);
            identify_and_route($pdo, $chat_id, $national_code, $mobile, $bot_token);
        } else {
            send_msg($chat_id, "لطفاً فقط شماره تماس خودتان را با دکمه‌ی زیر ارسال کنید:", $bot_token, kb_request_contact());
        }
        return;
    }

    // پیش‌فرض: از نو شروع کن
    send_msg($chat_id, "برای شروع، لطفاً /start را ارسال کنید.", $bot_token, kb_remove());
}

// =====================================================================
//  دیسپچ اصلی
// =====================================================================
if ($chat_type !== 'private') {
    handle_staff_group_message($pdo, $message, $chat_id, $chat_type, $chat_title,
        $incoming_message_id, $sender_user_id, $sender_name, $sender_username, $bot_token);
} else {
    handle_private_message($pdo, $message, $chat_id, $incoming_message_id, $text,
        $sender_user_id, $sender_name, $sender_username, $bot_token);
}
