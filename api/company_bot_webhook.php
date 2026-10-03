<?php
// فایل: api/company_bot_webhook.php
// ربات بله‌ی «شرکت‌ها» (جدا از ربات پرسنل). وب‌هوکش با ذخیره‌ی توکن در تنظیمات تنظیم می‌شود.
//
//  ۱) ورود: کاربر با دکمه‌ی «اشتراک‌گذاری شماره» شماره‌اش را می‌فرستد (بله با +98 می‌فرستد؛
//     در پنل با 09 ثبت شده - هر دو یکسان حساب می‌شوند). اگر شماره مالِ یکی از کاربرانِ پنل یا
//     کاربرانِ شرکت‌ها باشد، این گفتگو به همان حساب وصل می‌شود (دایره‌ی سبز در پنل) و از این به بعد
//     کدِ ورود به سایت هم همین‌جا فرستاده می‌شود.
//  ۲) کاربرِ شرکت: همان کارهای پنلِ شرکت در سایت - درخواست‌ها و جزئیاتشان، ثبت درخواست جدید،
//     ارسال مدرک، گفتگو با ما، دریافتِ بیمه‌نامه‌های صادرشده، بازیابی رمز.
//  ۳) همکار بیمه با ما (و مدیر): درخواست‌های شرکت‌ها، مدارکِ تگ‌نشده، پیام‌های شرکت‌ها و
//     پاسخ به آن‌ها، جستجوی صادره‌ها، خلاصه‌ی وضعیت، بازیابی رمز.
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_chat_state.php';

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;
if (!auth_schema_ready($pdo) || !cbot_token($pdo)) exit;

// ---------------------------------------------------------------------
//  کمکی‌ها
// ---------------------------------------------------------------------
function fa($s) { return auth_fa($s); }
function kb($rows) { return ['keyboard' => $rows, 'resize_keyboard' => true]; }
function ikb($rows) { return ['inline_keyboard' => $rows]; }
function contact_kb() { return kb([[['text' => '📱 ورود با شماره تلفن', 'request_contact' => true]]]); }
function say($chat, $text, $markup = null) { global $pdo; return cbot_send($pdo, $chat, $text, $markup); }

function st_get($chat) {
    global $pdo;
    $st = $pdo->prepare("SELECT state, temp FROM company_bot_state WHERE chat_id = ?");
    $st->execute([$chat]);
    $r = $st->fetch();
    return ['state' => $r['state'] ?? null, 'temp' => json_decode($r['temp'] ?? '', true) ?: []];
}
function st_set($chat, $state, $temp = []) {
    global $pdo;
    $pdo->prepare("INSERT INTO company_bot_state (chat_id, state, temp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), temp = VALUES(temp)")
        ->execute([$chat, $state, json_encode($temp, JSON_UNESCAPED_UNICODE)]);
}
// حالت پاک می‌شود ولی «حسابِ انتخاب‌شده» (برای شماره‌ای با چند حساب) می‌ماند
function st_reset($chat) { $s = st_get($chat); st_set($chat, null, isset($s['temp']['ctx']) ? ['ctx' => $s['temp']['ctx']] : []); }

// همه‌ی حساب‌هایی که به این گفتگو وصل‌اند
function chat_accounts($chat) {
    global $pdo;
    $out = [];
    $st = $pdo->prepare("SELECT * FROM users WHERE bale_chat_id = ? AND bot_linked_at IS NOT NULL AND COALESCE(is_deleted, 0) = 0");
    $st->execute([$chat]);
    foreach ($st->fetchAll() as $u) $out[] = ['type' => 'STAFF', 'user' => $u];
    $st = $pdo->prepare("SELECT * FROM company_portal_users WHERE bale_chat_id = ? AND bot_linked_at IS NOT NULL AND is_active = 1 AND COALESCE(is_deleted, 0) = 0");
    $st->execute([$chat]);
    foreach ($st->fetchAll() as $u) { $u['role'] = null; $out[] = ['type' => 'COMPANY', 'user' => $u]; }
    return $out;
}
function current_account($chat, $accounts) {
    if (!$accounts) return null;
    $ctx = st_get($chat)['temp']['ctx'] ?? null;
    foreach ($accounts as $a) if (auth_account_key($a) === $ctx) return $a;
    return $accounts[0];
}
function is_company($acc) { return $acc && $acc['type'] === 'COMPANY'; }
// همکار بیمه با ما و مدیرِ کل به بخشِ شرکت‌ها دسترسی دارند
function is_liaison($acc) { return $acc && $acc['type'] === 'STAFF' && in_array($acc['user']['role'], ['COMPANY_LIAISON', 'ADMIN'], true); }

function is_admin($acc) { return $acc && $acc['type'] === 'STAFF' && $acc['user']['role'] === 'ADMIN'; }

// ---------------------------------------------------------------------
//  اعلان‌های مدیر کل (فعالیت‌های پرسنل در ربات و مینی‌اپ - notify_admin)
// ---------------------------------------------------------------------
const NOTIF_BTN = '🔔 اعلان‌ها';
function admin_notif_count($userId) {
    global $pdo;
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM admin_notifications WHERE user_id = ?"); $st->execute([$userId]); return intval($st->fetchColumn()); }
    catch (Throwable $e) { return 0; }   // مایگریشن ۰۱۷ هنوز اجرا نشده
}
// همه‌ی اعلان‌ها در یک پیامِ جمع‌وجور (گروه‌بندی بر اساس روز، تکراری‌های پشتِ‌هم یکی می‌شوند)؛ بعد از ارسال پاک می‌شوند
function show_admin_notifs($chat, $acc, $multi) {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT id, text, created_at FROM admin_notifications WHERE user_id = ? ORDER BY id");
        $st->execute([$acc['user']['id']]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { say($chat, 'بخش اعلان‌ها هنوز راه‌اندازی نشده (مایگریشن ۰۱۷).', main_menu($acc, $multi)); return; }
    if (!$rows) { say($chat, '✅ اعلانِ تازه‌ای ندارید.', main_menu($acc, $multi)); return; }

    $lines = []; $day = null; $prev = null;
    foreach ($rows as $r) {
        $ts = strtotime($r['created_at']);
        $d = jalali_from_gregorian_ts_dotted($ts);
        if ($d !== $day) { $lines[] = ($day === null ? '' : "\n") . '📅 ' . fa($d); $day = $d; $prev = null; }
        if ($prev !== null && $prev['text'] === $r['text']) { $prev['n']++; $lines[count($lines) - 1] = $prev['line'] . ' (×' . fa($prev['n']) . ')'; continue; }
        $line = fa(date('H:i', $ts) . '  ' . $r['text']);
        $lines[] = $line;
        $prev = ['text' => $r['text'], 'line' => $line, 'n' => 1];
    }
    // هر پیامِ بله حداکثر ~۴۰۰۰ نویسه؛ اگر بیشتر بود چند تکه می‌شود
    $head = '🔔 اعلان‌ها (' . fa(count($rows)) . ")\n";
    $chunks = []; $cur = $head;
    foreach ($lines as $l) {
        if (mb_strlen($cur) + mb_strlen($l) + 1 > 3800) { $chunks[] = $cur; $cur = ''; }
        $cur .= ($cur === '' || $cur === $head ? '' : "\n") . $l;
    }
    $chunks[] = $cur;
    $maxId = end($rows)['id'];
    $sent = true;
    foreach ($chunks as $i => $c) {
        $last = $i === count($chunks) - 1;
        if (!say($chat, $last ? $c . "\n\n🗑 این اعلان‌ها از فهرستِ شما پاک شد." : $c, $last ? main_menu($acc, $multi, 0) : null)) $sent = false;
    }
    // فقط اگر واقعاً رسید پاک شود (اعلانی که همین حالا آمده و در این پیام نبود، می‌ماند)
    if ($sent) $pdo->prepare("DELETE FROM admin_notifications WHERE user_id = ? AND id <= ?")->execute([$acc['user']['id'], $maxId]);
}

function main_menu($acc, $multi = false, $notifCount = null) {
    if (is_company($acc)) {
        $rows = [
            [['text' => '📋 درخواست‌های من'], ['text' => '➕ ثبت درخواست جدید']],
            [['text' => '📎 ارسال مدرک'], ['text' => '💬 گفتگو با بیمه با ما']],
            [['text' => '🔎 جستجوی بیمه‌نامه'], ['text' => '👤 حساب من']],
            [['text' => '🔑 بازیابی رمز عبور'], ['text' => '🚪 خروج از حساب']],
        ];
    } elseif (is_liaison($acc)) {
        $rows = [];
        if (is_admin($acc)) { $n = $notifCount ?? admin_notif_count($acc['user']['id']); $rows[] = [['text' => NOTIF_BTN . ($n ? ' (' . fa($n) . ')' : '')]]; }
        $rows = array_merge($rows, [
            [['text' => '📥 درخواست‌های شرکت‌ها'], ['text' => '🗂 مدارک تگ‌نشده']],
            [['text' => '💬 پیام‌های شرکت‌ها'], ['text' => '🔎 جستجوی صادره']],
            [['text' => '📊 خلاصه وضعیت'], ['text' => '👤 حساب من']],
            [['text' => '🔑 بازیابی رمز عبور'], ['text' => '🚪 خروج از حساب']],
        ]);
    } else {
        $rows = [
            [['text' => '👤 حساب من'], ['text' => '🔑 بازیابی رمز عبور']],
            [['text' => '🚪 خروج از حساب']],
        ];
    }
    if ($multi) $rows[] = [['text' => '🔄 تغییر حساب']];
    return kb($rows);
}

function company_ids_of($acc) {
    global $pdo;
    $st = $pdo->prepare("SELECT c.id, c.name, c.allowed_insurers FROM company_portal_user_companies cpuc JOIN companies c ON c.id = cpuc.company_id WHERE cpuc.portal_user_id = ? ORDER BY c.name");
    $st->execute([$acc['user']['id']]);
    return $st->fetchAll();
}
function req_status_fa($s) {
    return ['NEW' => 'جدید', 'DOCS_PENDING' => 'در انتظار مدارک', 'DOCS_REVIEW' => 'در حال بررسی', 'READY_FOR_ISSUE' => 'آماده‌ی صدور',
            'ISSUED' => 'صادر شده', 'CANCELLED' => 'لغو شده'][$s] ?? $s;
}
function jdate_short($ts) { return $ts ? fa(jalali_from_gregorian_ts_dotted(strtotime($ts))) : '-'; }
function money_fa($n) { return fa(number_format((int)$n)); }

// دریافتِ فایلِ ارسالی (سند یا عکس) از بله و ذخیره در پوشه‌ی مقصد
function bot_save_incoming_file($message, $destDir, $allowZip = false) {
    global $pdo;
    $fileId = null; $name = null; $size = 0;
    if (!empty($message['document'])) {
        $fileId = $message['document']['file_id']; $name = $message['document']['file_name'] ?? 'file'; $size = intval($message['document']['file_size'] ?? 0);
    } elseif (!empty($message['photo'])) {
        $p = end($message['photo']); $fileId = $p['file_id']; $name = 'photo.jpg'; $size = intval($p['file_size'] ?? 0);
    }
    if (!$fileId) return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    if ($size > 20 * 1024 * 1024) return ['ok' => false, 'error' => 'حجم فایل بیشتر از ۲۰ مگابایت است.'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp']; if ($allowZip) $allowed[] = 'zip';
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'error' => 'فقط فایل PDF یا عکس' . ($allowZip ? ' یا زیپ' : '') . ' پذیرفته می‌شود.'];
    $info = cbot_api($pdo, 'getFile', ['file_id' => $fileId]);
    if (empty($info['file_path'])) return ['ok' => false, 'error' => 'دریافتِ فایل از بله ممکن نشد؛ دوباره بفرستید.'];
    $url = cbot_base() . '/file/bot' . cbot_token($pdo) . '/' . $info['file_path'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
    $bin = curl_exec($ch);
    curl_close($ch);
    if (!$bin) return ['ok' => false, 'error' => 'دریافتِ فایل از بله ممکن نشد؛ دوباره بفرستید.'];
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) return ['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه.'];
    $dest = unique_dest_path($destDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
    file_put_contents($dest, $bin);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) compress_image_if_needed($dest);
    return ['ok' => true, 'path' => $dest, 'orig_name' => $name];
}

// ---------------------------------------------------------------------
//  ورود با شماره
// ---------------------------------------------------------------------
function handle_contact($chat, $fromId, $contact) {
    global $pdo;
    if (!empty($contact['user_id']) && strval($contact['user_id']) !== strval($fromId)) {
        say($chat, 'لطفاً فقط شماره‌ی خودتان را با دکمه‌ی زیر بفرستید.', contact_kb()); return;
    }
    $phone = auth_norm_phone($contact['phone_number'] ?? '');
    $accounts = $phone ? auth_accounts_by_phone($pdo, $phone) : [];
    if (!$accounts) {
        say($chat, "❌ شماره‌ی " . fa($phone ?: ($contact['phone_number'] ?? '')) . " در سامانه‌ی «بیمه با ما» برای هیچ کاربری ثبت نشده است.\nاگر فکر می‌کنید اشتباهی شده، با مدیر سامانه تماس بگیرید.", contact_kb());
        return;
    }
    // این گفتگو قبلاً به حسابِ دیگری وصل بود؟ آن اتصال برداشته می‌شود
    $pdo->prepare("UPDATE users SET bale_chat_id = NULL, bot_linked_at = NULL WHERE bale_chat_id = ?")->execute([$chat]);
    $pdo->prepare("UPDATE company_portal_users SET bale_chat_id = NULL, bot_linked_at = NULL WHERE bale_chat_id = ?")->execute([$chat]);
    foreach ($accounts as $a) {
        $table = $a['type'] === 'STAFF' ? 'users' : 'company_portal_users';
        $pdo->prepare("UPDATE $table SET bale_chat_id = ?, bot_linked_at = NOW() WHERE id = ?")->execute([$chat, $a['user']['id']]);
    }
    st_set($chat, null, ['ctx' => auth_account_key($accounts[0])]);
    $accounts = chat_accounts($chat);
    $acc = current_account($chat, $accounts);
    $names = implode("\n", array_map(fn($a) => '• ' . $a['user']['full_name'] . ' (' . auth_role_fa($a['type'], $a['user']['role'] ?? null) . ')', $accounts));
    say($chat, "✅ {$acc['user']['full_name']} عزیز، خوش آمدید!\nاین گفتگو به حساب شما در «بیمه با ما» وصل شد:\n{$names}\n\n🔐 از این به بعد کدِ ورود به سایت هم همین‌جا برایتان فرستاده می‌شود." .
        (count($accounts) > 1 ? "\n\nبا «🔄 تغییر حساب» بین حساب‌هایتان جابه‌جا شوید." : ''), main_menu($acc, count($accounts) > 1));
}

// ---------------------------------------------------------------------
//  کاربرِ شرکت
// ---------------------------------------------------------------------
function co_list_requests($chat, $acc, $page = 0) {
    global $pdo;
    $ids = array_column(company_ids_of($acc), 'id');
    if (!$ids) { say($chat, 'این حساب به هیچ شرکتی وصل نیست.'); return; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT cr.*, c.name AS company_name,
                                (SELECT COUNT(*) FROM company_request_plates p WHERE p.request_id = cr.id) AS rows_count,
                                (SELECT COUNT(*) FROM company_request_plates p WHERE p.request_id = cr.id AND p.status = 'ISSUED') AS issued_count
                           FROM company_requests cr JOIN companies c ON c.id = cr.company_id
                          WHERE cr.company_id IN ($in) ORDER BY cr.created_at DESC LIMIT 8 OFFSET " . intval($page * 8));
    $st->execute($ids);
    $rows = $st->fetchAll();
    if (!$rows) { say($chat, $page ? 'درخواستِ دیگری نیست.' : 'هنوز درخواستی ثبت نکرده‌اید. با «➕ ثبت درخواست جدید» شروع کنید.'); return; }
    $btns = [];
    foreach ($rows as $r) {
        $btns[] = [['text' => '#' . fa($r['id']) . ' · ' . company_request_kind_fa($r['request_kind']) . ' · ' . req_status_fa($r['status'])
                     . ' · ' . fa($r['issued_count']) . '/' . fa($r['rows_count']) . ' صادر', 'callback_data' => 'req:' . $r['id']]];
    }
    if (count($rows) === 8) $btns[] = [['text' => 'قبلی‌ها ◀️', 'callback_data' => 'reqs:' . ($page + 1)]];
    say($chat, "📋 درخواست‌های شما (جدیدترین‌ها) - برای دیدن جزئیات روی هر کدام بزنید:", ikb($btns));
}

// آیا این درخواست مالِ یکی از شرکت‌های این کاربر است؟
function co_owned_request($acc, $requestId) {
    global $pdo;
    $ids = array_column(company_ids_of($acc), 'id');
    if (!$ids) return null;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ? AND cr.company_id IN ($in)");
    $st->execute(array_merge([$requestId], $ids));
    return $st->fetch() ?: null;
}

function request_detail_text($req, $forStaff = false) {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? ORDER BY id");
    $st->execute([$req['id']]);
    $plates = $st->fetchAll();
    $kind = $req['request_kind'] ?? 'NEW_POLICY';
    $t = "📄 درخواست #" . fa($req['id']) . " - {$req['company_name']}\n"
       . "نوع: " . company_request_kind_fa($kind) . " | بیمه‌گر: " . ($req['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد') . "\n"
       . "وضعیت: " . req_status_fa($req['status']) . " | ثبت: " . jdate_short($req['created_at']) . "\n";
    $counts = company_requested_counts_fa($req['requested_counts'] ?? null);
    if ($counts) $t .= "تعداد درخواستی: " . fa($counts) . "\n";
    if (trim((string)$req['request_text']) !== '') $t .= "متن: " . mb_substr($req['request_text'], 0, 300) . "\n";
    if (!$plates) return $t . "\nهنوز ردیفی (پلاک) برای این درخواست ثبت نشده؛ بعد از بررسیِ نامه اضافه می‌شود.";
    $t .= "\nردیف‌ها (" . fa(count($plates)) . "):";
    $st = $pdo->prepare("SELECT doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'");
    foreach (array_slice($plates, 0, 25) as $i => $p) {
        $st->execute([$p['id']]);
        $assigned = array_values(array_filter(array_column($st->fetchAll(), 'doc_type')));
        $missing = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assigned, $p['has_prev_body'], $kind);
        $t .= "\n" . fa($i + 1) . ") " . fa(company_row_label($p)) . " · " . insurance_type_fa($p['insurance_type']) . " · " . company_plate_status_fa($p['status'], $kind);
        if ($p['status'] === 'ISSUED' && $p['policy_number']) $t .= " · ش " . fa($p['policy_number']);
        if ($missing && $p['status'] !== 'ISSUED') $t .= "\n   ⚠️ کم دارد: " . implode('، ', array_values($missing));
    }
    if (count($plates) > 25) $t .= "\n... و " . fa(count($plates) - 25) . " ردیفِ دیگر (در سایت کامل دیده می‌شود)";
    return $t;
}

function co_show_request($chat, $acc, $requestId) {
    global $pdo;
    $req = co_owned_request($acc, $requestId);
    if (!$req) { say($chat, 'این درخواست پیدا نشد.'); return; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ? AND status = 'ISSUED' AND issued_file_path IS NOT NULL");
    $st->execute([$requestId]);
    $issued = intval($st->fetchColumn());
    $btns = [[['text' => '📎 ارسال مدرک برای این درخواست', 'callback_data' => 'reqdoc:' . $requestId]]];
    if ($issued) $btns[] = [['text' => '📥 دریافت بیمه‌نامه‌های صادرشده (' . fa($issued) . ')', 'callback_data' => 'reqpol:' . $requestId]];
    if ($req['letter_file_path']) $btns[] = [['text' => '📄 نامه‌ی درخواست', 'callback_data' => 'reqletter:' . $requestId]];
    say($chat, request_detail_text($req), ikb($btns));
}

function send_request_policies($chat, $requestId) {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? AND status = 'ISSUED' AND issued_file_path IS NOT NULL ORDER BY id");
    $st->execute([$requestId]);
    $n = 0;
    foreach ($st->fetchAll() as $p) $n += send_policy_file($chat, $p) ? 1 : 0;
    if (!$n) say($chat, 'هنوز بیمه‌نامه‌ی صادرشده‌ای برای این درخواست نیست.');
}
function send_policy_file($chat, $p) {
    global $pdo;
    $abs = dirname(__DIR__) . '/' . $p['issued_file_path'];
    if (!is_file($abs)) return false;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
    $name = sanitize_folder_name(insurance_type_fa($p['insurance_type']) . ' - ' . company_row_label($p)
            . ($p['policy_number'] ? ' - ' . policy_number_for_filename($p['policy_number']) : '')) . '.' . $ext;
    return (bool)cbot_send_file($pdo, $chat, $abs, 'بیمه‌نامه‌ی ' . insurance_type_fa($p['insurance_type']) . ' - ' . fa(company_row_label($p)), $name);
}

// ---- ثبتِ درخواستِ جدید (مرحله‌به‌مرحله، همان قواعدِ پنلِ سایت) ----
function nr_start($chat, $acc) {
    $cos = company_ids_of($acc);
    if (!$cos) { say($chat, 'این حساب به هیچ شرکتی وصل نیست.'); return; }
    if (count($cos) === 1) { nr_after_company($chat, $acc, $cos[0], []); return; }
    st_set($chat, 'NR', ['ctx' => auth_account_key($acc)]);
    say($chat, '🏢 برای کدام شرکت درخواست ثبت می‌کنید؟', ikb(array_map(fn($c) => [['text' => $c['name'], 'callback_data' => 'nrc:' . $c['id']]], $cos)));
}
function nr_after_company($chat, $acc, $co, $t) {
    $t['ctx'] = auth_account_key($acc); $t['company_id'] = intval($co['id']); $t['company_name'] = $co['name'];
    if (($co['allowed_insurers'] ?? 'BOTH') === 'BOTH') {
        st_set($chat, 'NR', $t);
        say($chat, '🏛 بیمه‌گر را انتخاب کنید:', ikb([[['text' => 'پاسارگاد', 'callback_data' => 'nri:PASARGAD'], ['text' => 'ایران', 'callback_data' => 'nri:IRAN']]]));
        return;
    }
    $t['insurer'] = $co['allowed_insurers'];
    nr_ask_kind($chat, $t);
}
function nr_ask_kind($chat, $t) {
    st_set($chat, 'NR', $t);
    say($chat, '📌 نوع درخواست:', ikb([[['text' => 'صدور بیمه‌نامه‌ی جدید', 'callback_data' => 'nrk:NEW_POLICY']],
                                       [['text' => 'الحاقیه / فسخ', 'callback_data' => 'nrk:ENDORSEMENT']]]));
}
function nr_count_keys($t) { return ($t['kind'] ?? 'NEW_POLICY') === 'NEW_POLICY' ? ['THIRDPARTY', 'BODY'] : ['ENDORSEMENT', 'CANCELLATION']; }
function nr_ask_next_count($chat, $t) {
    foreach (nr_count_keys($t) as $k) {
        if (!array_key_exists($k, $t['counts'] ?? [])) {
            $t['awaiting'] = 'count:' . $k;
            st_set($chat, 'NR', $t);
            say($chat, '🔢 تعداد «' . company_requested_count_labels()[$k] . '» را بنویسید (اگر ندارید ۰):', kb([[['text' => '۰'], ['text' => '۱'], ['text' => '۲']], [['text' => '❌ انصراف']]]));
            return;
        }
    }
    $t['awaiting'] = 'text';
    st_set($chat, 'NR', $t);
    say($chat, "✍️ توضیح یا متنِ درخواست را بنویسید (مثلاً پلاک‌ها و نوع بیمه).\nاگر فقط نامه می‌فرستید، «رد شدن» را بزنید.", kb([[['text' => 'رد شدن']], [['text' => '❌ انصراف']]]));
}
function nr_summary($chat, $t) {
    $counts = company_normalize_requested_counts($t['counts'] ?? []);
    $txt = "🧾 خلاصه‌ی درخواست:\nشرکت: {$t['company_name']}\nبیمه‌گر: " . (($t['insurer'] ?? 'PASARGAD') === 'IRAN' ? 'ایران' : 'پاسارگاد')
         . "\nنوع: " . company_request_kind_fa(company_kind_from_counts($counts, $t['kind'] ?? 'NEW_POLICY'))
         . "\nتعداد: " . fa(company_requested_counts_fa($counts) ?: '—')
         . "\nمتن: " . (($t['text'] ?? '') !== '' ? $t['text'] : '—')
         . "\nنامه: " . (!empty($t['letter']) ? '✅ پیوست شد' : '—');
    $t['awaiting'] = 'confirm';
    st_set($chat, 'NR', $t);
    say($chat, $txt, ikb([[['text' => '✅ ثبت نهایی', 'callback_data' => 'nrok'], ['text' => '❌ انصراف', 'callback_data' => 'nrno']]]));
}
function nr_submit($chat, $acc, $t) {
    global $pdo;
    $ids = array_column(company_ids_of($acc), 'id');
    $companyId = intval($t['company_id'] ?? 0);
    if (!in_array($companyId, array_map('intval', $ids), true)) { say($chat, 'شرکت نامعتبر است.'); return; }
    if (($t['text'] ?? '') === '' && empty($t['letter'])) { say($chat, 'متنِ درخواست یا فایلِ نامه لازم است.'); return; }
    $counts = company_normalize_requested_counts($t['counts'] ?? []);
    $kind = company_kind_from_counts($counts, company_valid_kind($t['kind'] ?? 'NEW_POLICY'));
    $insurer = ($t['insurer'] ?? 'PASARGAD') === 'IRAN' ? 'IRAN' : 'PASARGAD';
    $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, requested_counts, status) VALUES (?, ?, ?, ?, ?, ?, 'NEW')")
        ->execute([$companyId, $acc['user']['id'], $t['text'] ?? '', $insurer, $kind, $counts ? json_encode($counts) : null]);
    $requestId = intval($pdo->lastInsertId());
    ref_code_of($pdo, 'company_requests', $requestId);
    if (!empty($t['letter']) && is_file($t['letter'])) {
        $siteRoot = dirname(__DIR__);
        $rel = ltrim(str_replace($siteRoot, '', $t['letter']), '/');
        $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$rel, $requestId]);
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, status) VALUES (?, ?, ?, ?, ?, 'LETTER', 'UNASSIGNED')")
            ->execute([$companyId, $requestId, $acc['user']['id'], $rel, $t['letter_name'] ?? basename($rel)]);
    }
    st_reset($chat);
    say($chat, "✅ درخواست #" . fa($requestId) . " ثبت شد و به کارشناسان ما رسید. وضعیتش را از «📋 درخواست‌های من» ببینید.", main_menu($acc));
    cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "🆕 درخواست جدید از «{$t['company_name']}» (#" . fa($requestId) . ") - از ربات", ikb([[['text' => 'مشاهده', 'callback_data' => 'lreq:' . $requestId]]]));
}

// ---- ارسالِ مدرک ----
function doc_types_kb($requestId) {
    $types = company_doc_types();
    unset($types['car_card_or_title']);
    $types = ['letter' => 'نامه‌ی درخواست'] + $types;
    $rows = []; $row = [];
    foreach ($types as $k => $l) { $row[] = ['text' => $l, 'callback_data' => "dt:{$requestId}:{$k}"]; if (count($row) === 2) { $rows[] = $row; $row = []; } }
    if ($row) $rows[] = $row;
    return ikb($rows);
}
function co_pick_request_for_doc($chat, $acc) {
    global $pdo;
    $ids = array_column(company_ids_of($acc), 'id');
    if (!$ids) { say($chat, 'این حساب به هیچ شرکتی وصل نیست.'); return; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT cr.id, cr.request_kind, cr.status, c.name FROM company_requests cr JOIN companies c ON c.id = cr.company_id
                          WHERE cr.company_id IN ($in) AND cr.status NOT IN ('ISSUED','CANCELLED') ORDER BY cr.created_at DESC LIMIT 10");
    $st->execute($ids);
    $rows = $st->fetchAll();
    if (!$rows) { say($chat, 'درخواستِ بازی ندارید. اول با «➕ ثبت درخواست جدید» درخواست ثبت کنید.'); return; }
    say($chat, '📎 مدرک برای کدام درخواست است؟', ikb(array_map(fn($r) => [['text' => '#' . fa($r['id']) . ' · ' . $r['name'] . ' · ' . company_request_kind_fa($r['request_kind']), 'callback_data' => 'reqdoc:' . $r['id']]], $rows)));
}
function co_store_doc($chat, $acc, $t, $message) {
    global $pdo;
    $req = co_owned_request($acc, intval($t['request_id'] ?? 0));
    if (!$req) { say($chat, 'درخواست پیدا نشد.'); st_reset($chat); return; }
    $siteRoot = dirname(__DIR__);
    $saved = bot_save_incoming_file($message, company_unassigned_temp_path($siteRoot, $req['company_name']), true);
    if (!$saved['ok']) { say($chat, '❌ ' . $saved['error']); return; }
    $rel = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
    $isLetter = ($t['doc_type'] ?? '') === 'letter';
    $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$req['company_id'], $req['id'], $acc['user']['id'], $rel, $saved['orig_name'], $isLetter ? 'LETTER' : 'SUPPORTING_DOC',
                   $isLetter ? 'letter' : ($t['doc_type'] ?? null), $isLetter ? 'ASSIGNED' : 'UNASSIGNED']);
    $docId = $pdo->lastInsertId();
    if ($isLetter) {
        $placed = company_place_letter($pdo, $siteRoot, $req['id'], $saved['path'], strtolower(pathinfo($saved['path'], PATHINFO_EXTENSION)) ?: 'pdf');
        if ($placed) { $rel = $placed; $pdo->prepare("UPDATE company_documents SET file_path = ? WHERE id = ?")->execute([$rel, $docId]); }
        $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$rel, $req['id']]);
    }
    $t['count'] = intval($t['count'] ?? 0) + 1;
    st_set($chat, 'UPDOC', $t);
    say($chat, '✅ «' . ($isLetter ? 'نامه‌ی درخواست' : company_doc_type_label($t['doc_type'])) . '» دریافت شد (' . fa($t['count']) . ' فایل). فایل بعدی را بفرستید یا «✅ پایان ارسال» را بزنید.',
        kb([[['text' => '✅ پایان ارسال']]]));
}

// ---- گفتگو ----
function chat_history_text($companyId, $limit = 8) {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM company_chat_messages WHERE company_id = ? ORDER BY id DESC LIMIT " . intval($limit));
    $st->execute([$companyId]);
    $rows = array_reverse($st->fetchAll());
    if (!$rows) return 'هنوز پیامی رد و بدل نشده.';
    return implode("\n\n", array_map(fn($m) => ($m['sender_type'] === 'ADMIN' ? '🏢 بیمه با ما' : '👤 شرکت') . ' · ' . jdate_short($m['created_at'])
        . "\n" . ($m['message'] ?: '📎 فایل'), $rows));
}
function co_open_chat($chat, $acc, $companyId = null) {
    global $pdo;
    $cos = company_ids_of($acc);
    if (!$cos) { say($chat, 'این حساب به هیچ شرکتی وصل نیست.'); return; }
    if (!$companyId && count($cos) > 1) { say($chat, 'گفتگو برای کدام شرکت؟', ikb(array_map(fn($c) => [['text' => $c['name'], 'callback_data' => 'chatc:' . $c['id']]], $cos))); return; }
    $companyId = $companyId ?: intval($cos[0]['id']);
    if (!in_array($companyId, array_map('intval', array_column($cos, 'id')), true)) return;
    $pdo->prepare("UPDATE company_chat_messages SET is_read = 1 WHERE company_id = ? AND sender_type = 'ADMIN' AND is_read = 0")->execute([$companyId]);
    st_set($chat, 'CHAT', ['ctx' => auth_account_key($acc), 'company_id' => $companyId]);
    say($chat, "💬 گفتگو با «بیمه با ما»\n\n" . chat_history_text($companyId) . "\n\n✍️ پیام یا فایل‌تان را بفرستید؛ برای بازگشت «🔙 منوی اصلی» را بزنید.", kb([[['text' => '🔙 منوی اصلی']]]));
}
function company_name($companyId) {
    global $pdo;
    $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$companyId]);
    return (string)$st->fetchColumn();
}
function chat_store($chat, $acc, $t, $message, $text, $asAdmin) {
    global $pdo;
    $companyId = intval($t['company_id']);
    $filePath = null;
    if (!empty($message['document']) || !empty($message['photo'])) {
        $siteRoot = dirname(__DIR__);
        $saved = bot_save_incoming_file($message, temp_archive_root($siteRoot) . '/چت شرکت‌ها/' . sanitize_folder_name(company_name($companyId)));
        if (!$saved['ok']) { say($chat, '❌ ' . $saved['error']); return; }
        $filePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        if ($text === '') $text = trim($message['caption'] ?? '');
    }
    if ($text === '' && !$filePath) return;
    // گفتگوی قطع‌شده: هر طرف فقط اگر اجازه داشته باشد می‌فرستد
    if (!chat_company_can_send($pdo, $companyId, $asAdmin ? 'OURS' : 'CLIENT')) { say($chat, '⛔️ ' . ($asAdmin ? 'این گفتگو دوطرفه قطع شده است.' : CHAT_CLOSED_CLIENT_MSG)); return; }
    if ($asAdmin) {
        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_user_id, message, file_path) VALUES (?, 'ADMIN', ?, ?, ?)")
            ->execute([$companyId, $acc['user']['id'], $text ?: null, $filePath]);
        cbot_notify_company($pdo, $companyId, "💬 پیام تازه از «بیمه با ما»:\n" . ($text ?: '📎 یک فایل فرستاد'), ikb([[['text' => '↩️ پاسخ', 'callback_data' => 'chatc:' . $companyId]]]));
    } else {
        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_portal_user_id, message, file_path) VALUES (?, 'COMPANY', ?, ?, ?)")
            ->execute([$companyId, $acc['user']['id'], $text ?: null, $filePath]);
        cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "💬 پیام تازه از «" . company_name($companyId) . "» ({$acc['user']['full_name']}):\n" . ($text ?: '📎 یک فایل فرستاد'),
            ikb([[['text' => '↩️ پاسخ', 'callback_data' => 'lchat:' . $companyId]]]));
    }
    say($chat, '✅ فرستاده شد.');
}

function co_search($chat, $acc, $q) {
    global $pdo;
    $ids = array_column(company_ids_of($acc), 'id');
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    search_issued($chat, "cr.company_id IN ($in)", $ids, $q);
}
// جستجوی بیمه‌نامه‌های صادرشده با پلاک (هر بخشی از آن)، شماره شاسی، شماره بیمه‌نامه یا نام شرکت
function search_issued($chat, $scopeSql, $scopeParams, $q) {
    global $pdo;
    $q = trim(p2e_digits($q));
    if (mb_strlen($q) < 2) { say($chat, 'دست‌کم ۲ کاراکتر بنویسید.'); return; }
    $like = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);
    $st = $pdo->prepare("SELECT p.*, c.name AS company_name FROM company_request_plates p
                           JOIN company_requests cr ON cr.id = p.request_id JOIN companies c ON c.id = cr.company_id
                          WHERE $scopeSql AND p.status = 'ISSUED' AND (p.policy_number LIKE ? OR p.chassis_no LIKE ? OR c.name LIKE ?
                                OR CONCAT(p.plate_p1, p.plate_p2, p.plate_p4) LIKE ? OR CONCAT_WS(' ', p.plate_p1, p.plate_p2, p.plate_letter, p.plate_p4) LIKE ?)
                          ORDER BY p.issued_at DESC LIMIT 10");
    $st->execute(array_merge($scopeParams, [$like, $like, $like, $digits !== '' ? '%' . $digits . '%' : $like, $like]));
    $rows = $st->fetchAll();
    if (!$rows) { say($chat, '🔎 بیمه‌نامه‌ی صادرشده‌ای با «' . fa($q) . '» پیدا نشد.'); return; }
    say($chat, '🔎 نتیجه‌ها (برای دریافتِ فایل روی هر کدام بزنید):', ikb(array_map(fn($p) => [['text' =>
        fa(company_row_label($p)) . ' · ' . insurance_type_fa($p['insurance_type']) . ' · ' . $p['company_name'] . ($p['policy_number'] ? ' · ' . fa($p['policy_number']) : ''),
        'callback_data' => 'pol:' . $p['id']]], $rows)));
}

// ---------------------------------------------------------------------
//  همکار بیمه با ما / مدیر
// ---------------------------------------------------------------------
function li_summary($chat) {
    global $pdo;
    $q = fn($sql) => intval($pdo->query($sql)->fetchColumn());
    $t = "📊 خلاصه‌ی وضعیتِ شرکت‌ها\n\n"
       . "🆕 درخواست‌های جدید: " . fa($q("SELECT COUNT(*) FROM company_requests WHERE status = 'NEW'")) . "\n"
       . "⏳ در جریان (مدارک/بررسی/آماده): " . fa($q("SELECT COUNT(*) FROM company_requests WHERE status IN ('DOCS_PENDING','DOCS_REVIEW','READY_FOR_ISSUE')")) . "\n"
       . "🗂 مدارکِ تگ‌نشده: " . fa($q("SELECT COUNT(*) FROM company_documents WHERE status = 'UNASSIGNED'")) . "\n"
       . "💬 پیام‌های خوانده‌نشده‌ی شرکت‌ها: " . fa($q("SELECT COUNT(*) FROM company_chat_messages WHERE sender_type = 'COMPANY' AND is_read = 0")) . "\n"
       . "✅ صادره‌ی امروز: " . fa($q("SELECT COUNT(*) FROM company_request_plates WHERE status = 'ISSUED' AND DATE(issued_at) = CURDATE()"));
    say($chat, $t);
}
function li_list_requests($chat, $page = 0) {
    global $pdo;
    $rows = $pdo->query("SELECT cr.*, c.name AS company_name,
                                (SELECT COUNT(*) FROM company_documents d WHERE d.request_id = cr.id AND d.status = 'UNASSIGNED') AS pending_docs
                           FROM company_requests cr JOIN companies c ON c.id = cr.company_id
                          WHERE cr.status NOT IN ('ISSUED','CANCELLED') ORDER BY (cr.status = 'NEW') DESC, cr.created_at DESC
                          LIMIT 10 OFFSET " . intval($page * 10))->fetchAll();
    if (!$rows) { say($chat, $page ? 'موردِ دیگری نیست.' : 'درخواستِ بازی نیست. 👌'); return; }
    $btns = array_map(fn($r) => [['text' => ($r['status'] === 'NEW' ? '🆕 ' : '') . '#' . fa($r['id']) . ' · ' . $r['company_name'] . ' · ' . req_status_fa($r['status'])
        . ($r['pending_docs'] ? ' · 📎' . fa($r['pending_docs']) : ''), 'callback_data' => 'lreq:' . $r['id']]], $rows);
    if (count($rows) === 10) $btns[] = [['text' => 'قبلی‌ها ◀️', 'callback_data' => 'lreqs:' . ($page + 1)]];
    say($chat, '📥 درخواست‌های باز شرکت‌ها:', ikb($btns));
}
function li_show_request($chat, $requestId, $acc = null) {
    global $pdo;
    $st = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $st->execute([$requestId]);
    $req = $st->fetch();
    if (!$req) { say($chat, 'درخواست پیدا نشد.'); return; }
    $btns = [];
    if ($req['letter_file_path']) $btns[] = [['text' => '📄 نامه‌ی درخواست', 'callback_data' => 'lletter:' . $requestId]];
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_documents WHERE request_id = ? AND status = 'UNASSIGNED'");
    $st->execute([$requestId]);
    if ($n = intval($st->fetchColumn())) $btns[] = [['text' => '📎 مدارکِ تگ‌نشده (' . fa($n) . ')', 'callback_data' => 'ldocs:' . $requestId]];
    $btns[] = [['text' => '💬 پیام به ' . $req['company_name'], 'callback_data' => 'lchat:' . $req['company_id']]];
    say($chat, request_detail_text($req, true) . "\n\n" . (is_admin($acc)
        ? '(ثبت، ویرایش، تگ‌گذاریِ مدارک و صدور از پنلِ سایت انجام می‌شود.)'
        : '(در ربات فقط مشاهده ممکن است؛ ثبت و ویرایشِ درخواست فقط با مدیر کل است.)'), ikb($btns));
}
function li_unassigned($chat, $requestId = null) {
    global $pdo;
    $sql = "SELECT d.*, c.name AS company_name FROM company_documents d JOIN companies c ON c.id = d.company_id WHERE d.status = 'UNASSIGNED'";
    $params = [];
    if ($requestId) { $sql .= " AND d.request_id = ?"; $params[] = $requestId; }
    $st = $pdo->prepare($sql . " ORDER BY d.uploaded_at DESC LIMIT 15");
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) { say($chat, 'مدرکِ تگ‌نشده‌ای نیست. 👌'); return; }
    say($chat, '🗂 مدارکِ تگ‌نشده (برای دریافتِ فایل بزنید؛ تگ‌گذاری در سایت):', ikb(array_map(fn($d) => [['text' =>
        $d['company_name'] . ' · #' . fa($d['request_id'] ?: '-') . ' · ' . ($d['doc_type'] ? company_doc_type_label($d['doc_type']) : ($d['file_kind'] === 'LETTER' ? 'نامه' : 'مدرک'))
        . ' · ' . jdate_short($d['uploaded_at']), 'callback_data' => 'ldoc:' . $d['id']]], $rows)));
}
function li_chats($chat) {
    global $pdo;
    $rows = $pdo->query("SELECT c.id, c.name, SUM(m.sender_type = 'COMPANY' AND m.is_read = 0) AS unread, MAX(m.created_at) AS last_at
                           FROM company_chat_messages m JOIN companies c ON c.id = m.company_id
                          GROUP BY c.id, c.name ORDER BY unread DESC, last_at DESC LIMIT 12")->fetchAll();
    if (!$rows) { say($chat, 'هنوز پیامی از شرکت‌ها نیامده.'); return; }
    say($chat, '💬 گفتگوهای شرکت‌ها (برای دیدن و پاسخ بزنید):', ikb(array_map(fn($r) => [['text' =>
        ($r['unread'] ? '🔴 ' : '') . $r['name'] . ($r['unread'] ? ' (' . fa($r['unread']) . ' نخوانده)' : ''), 'callback_data' => 'lchat:' . $r['id']]], $rows)));
}
function li_open_chat($chat, $acc, $companyId) {
    global $pdo;
    $name = company_name($companyId);
    if ($name === '') { say($chat, 'شرکت پیدا نشد.'); return; }
    $pdo->prepare("UPDATE company_chat_messages SET is_read = 1 WHERE company_id = ? AND sender_type = 'COMPANY' AND is_read = 0")->execute([$companyId]);
    st_set($chat, 'LCHAT', ['ctx' => auth_account_key($acc), 'company_id' => $companyId]);
    say($chat, "💬 گفتگو با «{$name}»\n\n" . chat_history_text($companyId) . "\n\n✍️ پاسخ‌تان را بنویسید یا فایل بفرستید (به‌نام «بیمه با ما» فرستاده می‌شود). برای بازگشت «🔙 منوی اصلی».",
        kb([[['text' => '🔙 منوی اصلی']]]));
}

function send_rel_file($chat, $rel, $caption = '') {
    global $pdo;
    $abs = dirname(__DIR__) . '/' . ltrim((string)$rel, '/');
    if (is_dir($abs)) { say($chat, 'این مدرک یک پوشه (زیپِ استخراج‌شده) است؛ از سایت ببینید.'); return; }
    if (!is_file($abs) || !cbot_send_file($pdo, $chat, $abs, $caption)) say($chat, 'فایل روی سرور پیدا نشد یا ارسالش ممکن نشد.');
}

// ---------------------------------------------------------------------
//  مشترک
// ---------------------------------------------------------------------
function show_account($chat, $acc, $accounts) {
    $u = $acc['user'];
    $t = "👤 {$u['full_name']}\nنقش: " . auth_role_fa($acc['type'], $u['role'] ?? null) . "\nنام کاربری: {$u['username']}\nموبایل: " . fa($u['mobile_number'] ?: '-')
       . "\nاتصال به ربات: از " . jdate_short($u['bot_linked_at']);
    if (is_company($acc)) $t .= "\nشرکت‌ها: " . implode('، ', array_column(company_ids_of($acc), 'name'));
    if (count($accounts) > 1) $t .= "\n\nحساب‌های دیگرِ همین شماره: " . implode('، ', array_map(fn($a) => $a['user']['full_name'] . ' (' . auth_role_fa($a['type'], $a['user']['role'] ?? null) . ')',
        array_filter($accounts, fn($a) => auth_account_key($a) !== auth_account_key($acc))));
    say($chat, $t, main_menu($acc, count($accounts) > 1));
}
function request_reset($chat, $acc) {
    global $pdo;
    auth_purge_old_resets($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE user_type = ? AND user_id = ? AND status = 'PENDING'");
    $st->execute([$acc['type'], $acc['user']['id']]);
    if (intval($st->fetchColumn())) { say($chat, '⏳ درخواستِ بازیابی رمزِ شما قبلاً ثبت شده و در انتظار بررسیِ مدیر است. نتیجه همین‌جا برایتان فرستاده می‌شود.'); return; }
    $pdo->prepare("INSERT INTO password_reset_requests (user_type, user_id) VALUES (?, ?)")->execute([$acc['type'], $acc['user']['id']]);
    say($chat, "✅ درخواست بازیابی رمز عبور ثبت شد.\nبعد از تاییدِ مدیر، رمزِ جدید همین‌جا برایتان فرستاده می‌شود.\n\n💡 برای ورود به سایت بدونِ رمز، می‌توانید از «ورود با کد (بله)» استفاده کنید.");
    cbot_notify_staff($pdo, ['ADMIN'], "🔑 درخواست بازیابی رمز از {$acc['user']['full_name']} (" . auth_role_fa($acc['type'], $acc['user']['role'] ?? null) . ").\nدر پنل: کاربران پنل ← درخواست‌های بازیابی رمز.");
}

// ---------------------------------------------------------------------
//  دکمه‌های شیشه‌ای
// ---------------------------------------------------------------------
if (!empty($update['callback_query'])) {
    $cb = $update['callback_query'];
    $chat = $cb['message']['chat']['id'] ?? null;
    $data = (string)($cb['data'] ?? '');
    cbot_api($pdo, 'answerCallbackQuery', ['callback_query_id' => $cb['id']]);
    if (!$chat) exit;
    $accounts = chat_accounts($chat);
    $acc = current_account($chat, $accounts);
    if (!$acc) { say($chat, 'برای استفاده، اول با شماره‌ی خود وارد شوید:', contact_kb()); exit; }
    [$cmd, $a1, $a2] = array_pad(explode(':', $data, 3), 3, null);

    if ($cmd === 'ctx') {   // انتخابِ حساب (یک شماره با چند حساب)
        foreach ($accounts as $x) if (auth_account_key($x) === "$a1:$a2") { st_set($chat, null, ['ctx' => "$a1:$a2"]); say($chat, "حساب فعال: {$x['user']['full_name']} (" . auth_role_fa($x['type'], $x['user']['role'] ?? null) . ")", main_menu($x, true)); }
        exit;
    }
    if ($cmd === 'notifs') {   // دکمه‌ی زیرِ پیامِ «اعلان تازه دارید» - حتی اگر حسابِ فعالِ این گفتگو حسابِ دیگری باشد
        foreach (array_merge([$acc], $accounts) as $x) if (is_admin($x)) { st_set($chat, null, ['ctx' => auth_account_key($x)]); show_admin_notifs($chat, $x, count($accounts) > 1); break; }
        exit;
    }
    if (is_company($acc)) {
        switch ($cmd) {
            case 'reqs': co_list_requests($chat, $acc, intval($a1)); break;
            case 'req': co_show_request($chat, $acc, intval($a1)); break;
            case 'reqpol': if (co_owned_request($acc, intval($a1))) send_request_policies($chat, intval($a1)); break;
            case 'reqletter': if ($r = co_owned_request($acc, intval($a1))) send_rel_file($chat, $r['letter_file_path'], 'نامه‌ی درخواست #' . fa($r['id'])); break;
            case 'reqdoc':
                if (co_owned_request($acc, intval($a1))) say($chat, '📎 نوع مدرک را انتخاب کنید:', doc_types_kb(intval($a1)));
                break;
            case 'dt':
                if (co_owned_request($acc, intval($a1))) {
                    st_set($chat, 'UPDOC', ['ctx' => auth_account_key($acc), 'request_id' => intval($a1), 'doc_type' => $a2, 'count' => 0]);
                    say($chat, '📤 فایلِ «' . ($a2 === 'letter' ? 'نامه‌ی درخواست' : company_doc_type_label($a2)) . '» را بفرستید (PDF یا عکس؛ برای بازدید سلامت زیپ هم می‌شود). چند فایل را پشتِ‌هم بفرستید.', kb([[['text' => '✅ پایان ارسال']]]));
                }
                break;
            case 'pol':
                $ids = array_column(company_ids_of($acc), 'id');
                $in = implode(',', array_fill(0, max(1, count($ids)), '?'));
                $st = $pdo->prepare("SELECT p.* FROM company_request_plates p JOIN company_requests cr ON cr.id = p.request_id WHERE p.id = ? AND cr.company_id IN ($in) AND p.status = 'ISSUED'");
                $st->execute(array_merge([intval($a1)], $ids ?: [0]));
                if ($p = $st->fetch()) { if (!send_policy_file($chat, $p)) say($chat, 'فایل بیمه‌نامه روی سرور پیدا نشد.'); }
                break;
            case 'chatc': co_open_chat($chat, $acc, intval($a1)); break;
            case 'nrc':
                foreach (company_ids_of($acc) as $co) if (intval($co['id']) === intval($a1)) nr_after_company($chat, $acc, $co, st_get($chat)['temp']);
                break;
            case 'nri': $t = st_get($chat)['temp']; $t['insurer'] = $a1 === 'IRAN' ? 'IRAN' : 'PASARGAD'; nr_ask_kind($chat, $t); break;
            case 'nrk': $t = st_get($chat)['temp']; $t['kind'] = $a1 === 'NEW_POLICY' ? 'NEW_POLICY' : 'ENDORSEMENT'; $t['counts'] = []; nr_ask_next_count($chat, $t); break;
            case 'nrok': $s = st_get($chat); if ($s['state'] === 'NR') nr_submit($chat, $acc, $s['temp']); break;
            case 'nrno': st_reset($chat); say($chat, 'ثبت درخواست لغو شد.', main_menu($acc, count($accounts) > 1)); break;
        }
        exit;
    }
    if (is_liaison($acc)) {
        switch ($cmd) {
            case 'lreqs': li_list_requests($chat, intval($a1)); break;
            case 'lreq': li_show_request($chat, intval($a1), $acc); break;
            case 'lletter':
                $st = $pdo->prepare("SELECT letter_file_path FROM company_requests WHERE id = ?");
                $st->execute([intval($a1)]);
                send_rel_file($chat, $st->fetchColumn(), 'نامه‌ی درخواست #' . fa($a1));
                break;
            case 'ldocs': li_unassigned($chat, intval($a1)); break;
            case 'ldoc':
                $st = $pdo->prepare("SELECT file_path, orig_name FROM company_documents WHERE id = ?");
                $st->execute([intval($a1)]);
                if ($d = $st->fetch()) send_rel_file($chat, $d['file_path'], $d['orig_name'] ?: '');
                break;
            case 'lchat': li_open_chat($chat, $acc, intval($a1)); break;
            case 'pol':
                $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ? AND status = 'ISSUED'");
                $st->execute([intval($a1)]);
                if ($p = $st->fetch()) { if (!send_policy_file($chat, $p)) say($chat, 'فایل بیمه‌نامه روی سرور پیدا نشد.'); }
                break;
        }
    }
    exit;
}

// ---------------------------------------------------------------------
//  پیام‌ها
// ---------------------------------------------------------------------
$message = $update['message'] ?? null;
if (!$message || ($message['chat']['type'] ?? 'private') !== 'private') exit;
$chat = $message['chat']['id'];
$fromId = $message['from']['id'] ?? null;
$text = trim(p2e_digits((string)($message['text'] ?? '')));

if (!empty($message['contact'])) { handle_contact($chat, $fromId, $message['contact']); exit; }

$accounts = chat_accounts($chat);
$acc = current_account($chat, $accounts);
if (!$acc) {
    say($chat, "سلام 👋 به ربات «بیمه با ما» ویژه‌ی شرکت‌ها و همکاران خوش آمدید.\n\nبرای ورود، دکمه‌ی «📱 ورود با شماره تلفن» را بزنید تا شماره‌ی شما با اطلاعاتِ ثبت‌شده در سامانه تطبیق داده شود.", contact_kb());
    exit;
}
$multi = count($accounts) > 1;
$s = st_get($chat);

// دکمه‌های منوی اصلی همیشه کار می‌کنند و هر حالتِ نیمه‌کاره را می‌بندند
$menuButtons = ['📋 درخواست‌های من', '➕ ثبت درخواست جدید', '📎 ارسال مدرک', '💬 گفتگو با بیمه با ما', '🔎 جستجوی بیمه‌نامه',
                '📥 درخواست‌های شرکت‌ها', '🗂 مدارک تگ‌نشده', '💬 پیام‌های شرکت‌ها', '🔎 جستجوی صادره', '📊 خلاصه وضعیت',
                '👤 حساب من', '🔑 بازیابی رمز عبور', '🚪 خروج از حساب', '🔄 تغییر حساب', '🔙 منوی اصلی', '❌ انصراف', '/start', '/menu'];
if (mb_strpos($text, NOTIF_BTN) === 0) {   // «🔔 اعلان‌ها» یا «🔔 اعلان‌ها (۳)»
    st_reset($chat);
    if (is_admin($acc)) show_admin_notifs($chat, $acc, $multi);
    else say($chat, 'این گزینه فقط برای مدیر کل است.', main_menu($acc, $multi));
    exit;
}
if (in_array($text, $menuButtons, true)) {
    if ($s['state'] === 'NR' && !empty($s['temp']['letter']) && is_file($s['temp']['letter'])) @unlink($s['temp']['letter']);
    st_reset($chat);
    switch ($text) {
        case '/start': case '/menu': case '🔙 منوی اصلی': case '✅ پایان ارسال':
            say($chat, "{$acc['user']['full_name']} عزیز، از منوی زیر انتخاب کنید:", main_menu($acc, $multi)); break;
        case '❌ انصراف': say($chat, 'لغو شد.', main_menu($acc, $multi)); break;
        case '👤 حساب من': show_account($chat, $acc, $accounts); break;
        case '🔑 بازیابی رمز عبور': request_reset($chat, $acc); break;
        case '🚪 خروج از حساب':
            foreach ($accounts as $x) auth_unlink_bot($pdo, $x['type'], $x['user']['id']);
            say($chat, "از حساب خارج شدید. 👋\nتا دوباره وارد نشوید، کدِ ورود به سایت هم برایتان فرستاده نمی‌شود.", contact_kb());
            break;
        case '🔄 تغییر حساب':
            say($chat, 'کدام حساب؟', ikb(array_map(fn($x) => [['text' => $x['user']['full_name'] . ' - ' . auth_role_fa($x['type'], $x['user']['role'] ?? null), 'callback_data' => 'ctx:' . auth_account_key($x)]], $accounts)));
            break;
        default:
            if (is_company($acc)) {
                if ($text === '📋 درخواست‌های من') co_list_requests($chat, $acc);
                elseif ($text === '➕ ثبت درخواست جدید') nr_start($chat, $acc);
                elseif ($text === '📎 ارسال مدرک') co_pick_request_for_doc($chat, $acc);
                elseif ($text === '💬 گفتگو با بیمه با ما') co_open_chat($chat, $acc);
                elseif ($text === '🔎 جستجوی بیمه‌نامه') { st_set($chat, 'SEARCH', ['ctx' => auth_account_key($acc)]); say($chat, '🔎 پلاک (مثلاً ۲۱۶۹۳۳۱ یا بخشی از آن)، شماره شاسی یا شماره بیمه‌نامه را بنویسید:', kb([[['text' => '🔙 منوی اصلی']]])); }
                else say($chat, 'این گزینه برای حساب شما نیست.', main_menu($acc, $multi));
            } elseif (is_liaison($acc)) {
                if ($text === '📥 درخواست‌های شرکت‌ها') li_list_requests($chat);
                elseif ($text === '🗂 مدارک تگ‌نشده') li_unassigned($chat);
                elseif ($text === '💬 پیام‌های شرکت‌ها') li_chats($chat);
                elseif ($text === '📊 خلاصه وضعیت') li_summary($chat);
                elseif ($text === '🔎 جستجوی صادره') { st_set($chat, 'SEARCH', ['ctx' => auth_account_key($acc)]); say($chat, '🔎 پلاک، شماره شاسی، شماره بیمه‌نامه یا نام شرکت را بنویسید:', kb([[['text' => '🔙 منوی اصلی']]])); }
                else say($chat, 'این گزینه برای حساب شما نیست.', main_menu($acc, $multi));
            } else {
                say($chat, 'این گزینه برای حساب شما نیست.', main_menu($acc, $multi));
            }
    }
    exit;
}
if ($text === '✅ پایان ارسال') {
    $n = intval($s['temp']['count'] ?? 0);
    st_reset($chat);
    say($chat, $n ? '✅ ' . fa($n) . ' فایل ثبت شد و کارشناسان ما بررسی می‌کنند.' : 'فایلی فرستاده نشد.', main_menu($acc, $multi));
    if ($n && is_company($acc) && ($rid = intval($s['temp']['request_id'] ?? 0))) {
        cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "📎 " . fa($n) . " مدرکِ تازه برای درخواست #" . fa($rid) . " از ربات رسید ({$acc['user']['full_name']}).", ikb([[['text' => 'مشاهده', 'callback_data' => 'lreq:' . $rid]]]));
    }
    exit;
}

// حالت‌های در جریان
switch ($s['state']) {
    case 'NR':
        if (!is_company($acc)) break;
        $t = $s['temp']; $aw = $t['awaiting'] ?? '';
        if (strpos($aw, 'count:') === 0) {
            if (!preg_match('/^\d{1,4}$/', $text)) { say($chat, 'فقط عدد بنویسید (مثلاً ۳ یا ۰).'); exit; }
            $t['counts'][substr($aw, 6)] = intval($text);
            nr_ask_next_count($chat, $t);
            exit;
        }
        if ($aw === 'text') {
            if ($text === '' && (!empty($message['document']) || !empty($message['photo']))) { $t['text'] = ''; $t['awaiting'] = 'letter'; }
            else { $t['text'] = $text === 'رد شدن' ? '' : mb_substr($text, 0, 2000); $t['awaiting'] = 'letter'; st_set($chat, 'NR', $t);
                   say($chat, '📄 فایلِ نامه‌ی درخواست را بفرستید (PDF یا عکس)، یا «بدون نامه» را بزنید.', kb([[['text' => 'بدون نامه']], [['text' => '❌ انصراف']]])); exit; }
        }
        if (($t['awaiting'] ?? '') === 'letter') {
            if ($text === 'بدون نامه') {
                if (($t['text'] ?? '') === '') { say($chat, 'بدونِ نامه، متنِ درخواست لازم است. متن را بنویسید:'); $t['awaiting'] = 'text'; st_set($chat, 'NR', $t); exit; }
                nr_summary($chat, $t); exit;
            }
            if (!empty($message['document']) || !empty($message['photo'])) {
                $cn = company_name(intval($t['company_id']));
                $saved = bot_save_incoming_file($message, company_unassigned_temp_path(dirname(__DIR__), $cn));
                if (!$saved['ok']) { say($chat, '❌ ' . $saved['error']); exit; }
                $t['letter'] = $saved['path']; $t['letter_name'] = $saved['orig_name'];
                nr_summary($chat, $t); exit;
            }
            say($chat, 'فایلِ نامه را بفرستید یا «بدون نامه» را بزنید.'); exit;
        }
        if ($aw === 'confirm') { say($chat, 'برای ثبت، دکمه‌ی «✅ ثبت نهایی» زیرِ خلاصه را بزنید.'); exit; }
        break;
    case 'UPDOC':
        if (is_company($acc) && (!empty($message['document']) || !empty($message['photo']))) { co_store_doc($chat, $acc, $s['temp'], $message); exit; }
        if (is_company($acc)) { say($chat, 'فایل را بفرستید یا «✅ پایان ارسال» را بزنید.'); exit; }
        break;
    case 'CHAT':
        if (is_company($acc)) { chat_store($chat, $acc, $s['temp'], $message, $text, false); exit; }
        break;
    case 'LCHAT':
        if (is_liaison($acc)) { chat_store($chat, $acc, $s['temp'], $message, $text, true); exit; }
        break;
    case 'SEARCH':
        if ($text === '') break;
        if (is_company($acc)) co_search($chat, $acc, $text);
        elseif (is_liaison($acc)) search_issued($chat, '1=1', [], $text);
        exit;
}

say($chat, "{$acc['user']['full_name']} عزیز، از منوی زیر انتخاب کنید:", main_menu($acc, $multi));
