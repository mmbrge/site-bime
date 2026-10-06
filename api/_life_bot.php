<?php
// فایل: api/_life_bot.php
// ربات بله‌ی «بیمه عمر» (توکنِ جدا): شناساییِ همکار (با شماره) و مشتری (شماره + کد ملی)، تنظیمات و یادآوری‌ها.
//  - همکار: کاربرِ پنل با دسترسیِ بیمه عمر؛ در ربات همان کارهایی را می‌کند که در سایت اجازه دارد.
//  - مشتری: بیمه‌گذار؛ با شماره‌ی خودش (دکمه‌ی اشتراکِ شماره) و کد ملی شناسایی می‌شود و اقساطش را می‌بیند.
//  - یادآوری: چند روز قبل از سررسید و برای اقساطِ معوق (هر چند روز یک بار)، و خلاصه‌ی روزانه‌ی پیگیری‌ها برای همکاران.

require_once __DIR__ . '/_life.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_perm.php';

function lbot_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    life_ensure($pdo);
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS life_bot_state (chat_id BIGINT PRIMARY KEY, state VARCHAR(40) NULL, temp TEXT NULL, updated_at DATETIME NULL) $o",
        // kind: STAFF (کاربرِ پنل) | CUSTOMER (بیمه‌گذار با کد ملی)
        "CREATE TABLE IF NOT EXISTS life_bot_links (chat_id BIGINT PRIMARY KEY, kind VARCHAR(10) NOT NULL, user_id INT NULL, nid VARCHAR(20) NULL, phone VARCHAR(20) NULL,
            name VARCHAR(190) NULL, remind TINYINT NOT NULL DEFAULT 1, linked_at DATETIME NULL, last_seen DATETIME NULL, KEY k_nid (nid), KEY k_user (user_id)) $o",
        "CREATE TABLE IF NOT EXISTS life_reminders (id INT AUTO_INCREMENT PRIMARY KEY, installment_id INT NULL, policy_id INT NULL, chat_id BIGINT NOT NULL, kind VARCHAR(12) NOT NULL,
            sent_on DATE NOT NULL, sent_at DATETIME NULL, by_user INT NULL, KEY k_inst (installment_id, kind), KEY k_chat (chat_id, sent_on)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[lbot_ensure] ' . $e->getMessage()); } }
}

// ---------------------------------------------------------------------
//  تنظیمات
// ---------------------------------------------------------------------
function lbot_defaults() {
    return [
        'remind_enabled' => 1,          // یادآوری به مشتری
        'remind_before_days' => 3,      // چند روز قبل از سررسید
        'remind_on_due' => 1,           // روزِ سررسید هم
        'remind_overdue_every' => 7,    // معوق: هر چند روز یک بار (۰ = خاموش)
        'remind_overdue_max' => 4,      // حداکثر دفعاتِ یادآوریِ معوق برای هر قسط
        'remind_hour' => 9,             // از این ساعت به بعد فرستاده می‌شود
        'staff_digest' => 1,            // خلاصه‌ی روزانه‌ی پیگیری‌ها برای همکاران
        'contact_text' => 'برای هماهنگی با کارشناسانِ بیمه عمرِ «بیمه با ما» تماس بگیرید.',
        'welcome_text' => 'به ربات «بیمه عمرِ بیمه با ما» خوش آمدید.',
        'remind_text' => "{نام} عزیز، سلام 🌷\nقسطِ {شماره_قسط} بیمه‌نامه‌ی عمرِ شما به شماره‌ی {شماره_بیمه_نامه} به مبلغِ {مبلغ} ریال، {زمان}.\nشناسه‌ی واریز: {شناسه_واریز}",
        'cron_key' => '',
    ];
}
function lbot_settings($pdo) {
    $s = json_decode((string)life_setting($pdo, 'life_bot_settings', ''), true);
    $s = array_merge(lbot_defaults(), is_array($s) ? $s : []);
    if ($s['cron_key'] === '') { $s['cron_key'] = bin2hex(random_bytes(12)); life_setting_set($pdo, 'life_bot_settings', json_encode($s, JSON_UNESCAPED_UNICODE)); }
    return $s;
}
function lbot_settings_save($pdo, array $in) {
    $s = lbot_settings($pdo);
    foreach (['remind_enabled', 'remind_on_due', 'staff_digest'] as $k) if (array_key_exists($k, $in)) $s[$k] = !empty($in[$k]) ? 1 : 0;
    foreach (['remind_before_days' => [0, 30], 'remind_overdue_every' => [0, 60], 'remind_overdue_max' => [0, 30], 'remind_hour' => [0, 23]] as $k => [$a, $b])
        if (array_key_exists($k, $in)) $s[$k] = max($a, min($b, intval(p2e_digits((string)$in[$k]))));
    foreach (['contact_text', 'welcome_text', 'remind_text'] as $k) if (array_key_exists($k, $in)) $s[$k] = mb_substr(trim((string)$in[$k]), 0, 1500);
    life_setting_set($pdo, 'life_bot_settings', json_encode($s, JSON_UNESCAPED_UNICODE));
    return $s;
}

// ---------------------------------------------------------------------
//  API بله
// ---------------------------------------------------------------------
function lbot_token($pdo) { static $t = null; if ($t === null) $t = (string)life_setting($pdo, 'life_bot_token', ''); return $t; }
function lbot_api($pdo, $method, $payload, $multipart = false) {
    $token = lbot_token($pdo);
    if ($token === '' || !function_exists('curl_init')) return null;
    $ch = curl_init(cbot_base() . "/bot{$token}/{$method}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $multipart ? 60 : 8, CURLOPT_CONNECTTIMEOUT => 5]);
    if ($multipart) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    else { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); }
    $res = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    return (is_array($d) && !empty($d['ok'])) ? ($d['result'] ?? true) : null;
}
function lbot_send($pdo, $chat, $text, $markup = null) {
    if (!$chat) return null;
    $p = ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000)];
    if ($markup) $p['reply_markup'] = $markup;
    return lbot_api($pdo, 'sendMessage', $p);
}
function lbot_send_file($pdo, $chat, $abs, $caption = '', $name = null) {
    if (!$chat || !is_file($abs)) return null;
    return lbot_api($pdo, 'sendDocument', ['chat_id' => $chat, 'document' => new CURLFile($abs, null, $name ?: basename($abs)), 'caption' => $caption], true);
}
// فایلِ رسیده از بله (عکس یا سند) => مسیرِ ذخیره‌شده در پوشه‌ی مقصد
function lbot_download($pdo, array $message, $destDir, $baseName) {
    $fileId = null; $name = 'file.jpg'; $size = 0;
    if (!empty($message['document'])) { $fileId = $message['document']['file_id']; $name = $message['document']['file_name'] ?? 'file'; $size = intval($message['document']['file_size'] ?? 0); }
    elseif (!empty($message['photo'])) { $p = end($message['photo']); $fileId = $p['file_id']; $size = intval($p['file_size'] ?? 0); }
    if (!$fileId) return ['error' => 'فایلی دریافت نشد.'];
    if ($size > 20 * 1048576) return ['error' => 'حجمِ فایل بیشتر از ۲۰ مگابایت است.'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) return ['error' => 'فقط عکس یا PDF پذیرفته می‌شود.'];
    $info = lbot_api($pdo, 'getFile', ['file_id' => $fileId]);
    if (empty($info['file_path'])) return ['error' => 'دریافتِ فایل از بله ممکن نشد؛ دوباره بفرستید.'];
    $ch = curl_init(cbot_base() . '/file/bot' . lbot_token($pdo) . '/' . $info['file_path']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
    $bin = curl_exec($ch);
    curl_close($ch);
    if (!$bin) return ['error' => 'دریافتِ فایل از بله ممکن نشد؛ دوباره بفرستید.'];
    if (!life_mkdir($destDir)) return ['error' => 'پوشه‌ی بایگانی ساخته نشد.'];
    $dest = $destDir . '/' . sanitize_folder_name($baseName) . '.' . $ext; $k = 2;
    while (file_exists($dest)) $dest = $destDir . '/' . sanitize_folder_name($baseName) . ' (' . $k++ . ').' . $ext;
    file_put_contents($dest, $bin);
    return ['path' => $dest];
}

// ---------------------------------------------------------------------
//  هویت
// ---------------------------------------------------------------------
function lbot_link($pdo, $chat) {
    $st = $pdo->prepare("SELECT * FROM life_bot_links WHERE chat_id = ?");
    $st->execute([$chat]);
    return $st->fetch() ?: null;
}
// دسترسی‌های بیمه عمرِ یک کاربرِ پنل (مثلِ سایت)
function lbot_perms($pdo, $uid) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
    $st->execute([intval($uid)]);
    $role = $st->fetchColumn();
    if ($role === false) return [];
    if ($role === 'ADMIN') { $o = []; foreach (perm_pages() as $k => $v) if (strpos($k, 'life-') === 0) $o[$k] = $v[1]; return $o; }
    $c = perm_user_custom($pdo, $uid);
    $p = $c !== null ? $c : perm_role_defaults($role);
    return array_filter($p, function ($k) { return strpos($k, 'life-') === 0; }, ARRAY_FILTER_USE_KEY);
}
function lbot_can(array $perms, $spec) { return perm_allows($perms, $spec); }
// کاربرِ پنلی که شماره‌اش این است و به بیمه عمر دسترسی دارد
function lbot_staff_by_phone($pdo, $phone) {
    foreach (auth_accounts_by_phone($pdo, $phone) as $a) if ($a['type'] === 'STAFF' && lbot_perms($pdo, $a['user']['id'])) return $a['user'];
    return null;
}
// بیمه‌نامه‌هایی که این مشتری (کد ملی + شماره) اجازه‌ی دیدنشان را دارد
function lbot_customer_policies($pdo, $nid, $phone) {
    $st = $pdo->prepare("SELECT * FROM life_policies WHERE holder_nid = ? ORDER BY issue_j DESC, id DESC");
    $st->execute([$nid]);
    $o = [];
    foreach ($st->fetchAll() as $p) if (in_array($phone, array_column(life_phone_list($p), 'phone'), true)) $o[] = $p;
    return $o;
}
function lbot_footer($pdo) {
    try { return (string)$pdo->query("SELECT footer_text FROM life_templates ORDER BY is_default DESC, file_path IS NULL DESC, id LIMIT 1")->fetchColumn(); }
    catch (Throwable $e) { return ''; }
}
const LBOT_ST_ICON = ['PAID' => '✅', 'PARTIAL' => '🟡', 'OVERDUE' => '🔴', 'DUE' => '⏳'];

// ---------------------------------------------------------------------
//  یادآوری‌ها
// ---------------------------------------------------------------------
function lbot_remind_text($pdo, array $s, array $p, array $i, $when) {
    return strtr($s['remind_text'], ['{نام}' => $p['holder_name'] ?: 'بیمه‌گذار', '{شماره_قسط}' => life_fa($i['inst_no']), '{شماره_بیمه_نامه}' => life_fa($p['policy_no']),
        '{مبلغ}' => life_money($i['rem'] ?? $i['amount']), '{سررسید}' => life_fa($i['due_j']), '{زمان}' => $when, '{شناسه_واریز}' => life_fa($i['pay_id'] ?: $p['pay_id'] ?: '—')]);
}
// یادآوریِ یک قسط به همه‌ی گفتگوهای مشتریِ این بیمه‌نامه؛ تعدادِ ارسال‌ها را برمی‌گرداند
function lbot_remind_inst($pdo, array $p, array $i, $kind, $when, $byUser = null) {
    $s = lbot_settings($pdo);
    $st = $pdo->prepare("SELECT * FROM life_bot_links WHERE kind = 'CUSTOMER' AND nid = ?" . ($byUser ? '' : ' AND remind = 1'));
    $st->execute([$p['holder_nid']]);
    $n = 0;
    $txt = lbot_remind_text($pdo, $s, $p, $i, $when);
    $foot = lbot_footer($pdo);
    foreach ($st->fetchAll() as $l) {
        if (!in_array($l['phone'], array_column(life_phone_list($p), 'phone'), true)) continue;
        $ok = lbot_send($pdo, $l['chat_id'], '🔔 ' . $txt . ($foot !== '' ? "\n\n" . $foot : ''), ['inline_keyboard' => [[['text' => '📋 مشاهده‌ی اقساط', 'callback_data' => 'cp:' . $p['id']]]]]);
        if ($ok) {
            $n++;
            $pdo->prepare("INSERT INTO life_reminders (installment_id, policy_id, chat_id, kind, sent_on, sent_at, by_user) VALUES (?, ?, ?, ?, CURDATE(), NOW(), ?)")
                ->execute([$i['id'] ?? null, $p['id'], $l['chat_id'], $kind, $byUser]);
        }
    }
    if ($n && $byUser) life_sys_note($pdo, $p['id'], 'یادآوریِ قسطِ ' . $i['inst_no'] . ' در ربات بله برای بیمه‌گذار فرستاده شد.', $byUser, $i['id'] ?? null);
    return $n;
}
// اجرای یادآوری‌های خودکار (کرون یا اجرای تنبل، حداکثر ساعتی یک بار)
function lbot_run_reminders($pdo, $force = false) {
    lbot_ensure($pdo);
    $s = lbot_settings($pdo);
    $out = ['before' => 0, 'due' => 0, 'overdue' => 0, 'digest' => 0, 'skipped' => null];
    if (lbot_token($pdo) === '') { $out['skipped'] = 'توکنِ ربات تنظیم نشده است.'; return $out; }
    if (!$force) {
        $last = intval(life_setting($pdo, 'life_bot_last_run', 0));
        if ($last > time() - 3600) { $out['skipped'] = 'کمتر از یک ساعت از اجرای قبلی گذشته است.'; return $out; }
        if (intval(date('G')) < intval($s['remind_hour'])) { $out['skipped'] = 'هنوز به ساعتِ ارسال نرسیده است.'; return $out; }
    }
    life_setting_set($pdo, 'life_bot_last_run', time());
    $today = date('Y-m-d');
    $sentToday = $pdo->prepare("SELECT COUNT(*) FROM life_reminders WHERE installment_id = ? AND kind = ? AND sent_on = CURDATE()");
    $prev = $pdo->prepare("SELECT COUNT(*), MAX(sent_on) FROM life_reminders WHERE installment_id = ? AND kind = ?");
    if (!empty($s['remind_enabled'])) {
        // فقط بیمه‌گذارانی که به ربات وصل‌اند
        $nids = $pdo->query("SELECT DISTINCT nid FROM life_bot_links WHERE kind = 'CUSTOMER' AND remind = 1 AND nid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        if ($nids) {
            $in = implode(',', array_fill(0, count($nids), '?'));
            $rem = life_sql_rem('i');
            $st = $pdo->prepare("SELECT i.*, $rem AS rem FROM life_installments i JOIN life_policies p ON p.id = i.policy_id WHERE p.holder_nid IN ($in) AND $rem > 0 AND i.due_g IS NOT NULL
                                    AND i.due_g <= DATE_ADD(CURDATE(), INTERVAL " . intval($s['remind_before_days']) . " DAY) ORDER BY i.due_g");
            $st->execute($nids);
            foreach ($st->fetchAll() as $i) {
                $p = life_policy_row($pdo, $i['policy_id']);
                if (!$p) continue;
                $days = intval((strtotime($i['due_g']) - strtotime($today)) / 86400);
                if ($days > 0) {
                    $prev->execute([$i['id'], 'before']); [$c] = $prev->fetch(PDO::FETCH_NUM);
                    if (intval($c)) continue;   // هر قسط یک بار قبل از سررسید
                    $out['before'] += lbot_remind_inst($pdo, $p, $i, 'before', life_fa($days) . ' روزِ دیگر (' . life_fa($i['due_j']) . ') سررسید می‌شود') ? 1 : 0;
                } elseif ($days === 0) {
                    if (empty($s['remind_on_due'])) continue;
                    $sentToday->execute([$i['id'], 'due']); if (intval($sentToday->fetchColumn())) continue;
                    $out['due'] += lbot_remind_inst($pdo, $p, $i, 'due', 'امروز سررسید می‌شود') ? 1 : 0;
                } else {
                    $every = intval($s['remind_overdue_every']);
                    if ($every <= 0) continue;
                    $prev->execute([$i['id'], 'overdue']); [$c, $lastOn] = $prev->fetch(PDO::FETCH_NUM);
                    if (intval($c) >= intval($s['remind_overdue_max'])) continue;
                    if ($lastOn && strtotime($lastOn) > strtotime($today) - $every * 86400) continue;
                    $out['overdue'] += lbot_remind_inst($pdo, $p, $i, 'overdue', life_fa(-$days) . ' روز از سررسیدش (' . life_fa($i['due_j']) . ') گذشته و هنوز پرداخت نشده است') ? 1 : 0;
                }
            }
        }
    }
    // خلاصه‌ی روزانه برای همکاران: پیگیری‌های سررسیده و معوق‌های بیمه‌نامه‌هایی که پیگیری می‌کنند
    if (!empty($s['staff_digest'])) {
        foreach ($pdo->query("SELECT * FROM life_bot_links WHERE kind = 'STAFF' AND remind = 1")->fetchAll() as $l) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM life_reminders WHERE chat_id = ? AND kind = 'digest' AND sent_on = CURDATE()");
            $chk->execute([$l['chat_id']]);
            if (intval($chk->fetchColumn())) continue;
            $fu = life_list($pdo, ['followup' => 1, 'follower' => $l['user_id']], 1, 5);
            $ov = life_list($pdo, ['status' => 'overdue', 'follower' => $l['user_id']], 1, 5);
            if (!$fu['total'] && !$ov['total']) continue;
            $t = "☀️ خلاصه‌ی امروزِ بیمه عمر\n📅 پیگیری‌های سررسیده: " . life_fa($fu['total']) . "\n🔴 بیمه‌نامه‌های معوقِ شما: " . life_fa($ov['total']) . ' (' . life_money($ov['sum']['overdue']) . ' ریال)';
            $btn = [];
            foreach (array_slice(array_merge($fu['rows'], $ov['rows']), 0, 6) as $r) $btn[$r['id']] = [['text' => ($r['overdue_count'] ? '🔴 ' : '📅 ') . $r['holder_name'] . ' · ' . life_fa($r['policy_no']), 'callback_data' => 'p:' . $r['id']]];
            if (lbot_send($pdo, $l['chat_id'], $t, ['inline_keyboard' => array_values($btn)])) {
                $out['digest']++;
                $pdo->prepare("INSERT INTO life_reminders (chat_id, kind, sent_on, sent_at) VALUES (?, 'digest', CURDATE(), NOW())")->execute([$l['chat_id']]);
            }
        }
    }
    return $out;
}
// اجرای تنبل بعد از پایانِ پاسخ (اگر کرون تنظیم نشده باشد هم یادآوری‌ها می‌روند)
function lbot_lazy_reminders($pdo) {
    if (lbot_token($pdo) === '' || intval(life_setting($pdo, 'life_bot_last_run', 0)) > time() - 3600) return;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(120);
        try { lbot_run_reminders($pdo); } catch (Throwable $e) { error_log('[lbot reminders] ' . $e->getMessage()); }
    });
}
