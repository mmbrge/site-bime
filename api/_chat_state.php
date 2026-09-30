<?php
// فایل: api/_chat_state.php
// وضعیتِ «قطعِ گفتگو» و «پیام‌های پنهان» (مایگریشن ۰۲۳). جدا از _chat_core.php است تا ربات‌ها هم
// (که پیامِ کاربر را مستقیم ثبت می‌کنند) بتوانند قبل از ثبت بپرسند گفتگو باز است یا نه.
//   thread : «P:<person>» / «T:<ticket>» / «C:<company>» / «S:<userA>-<userB>» (دو شناسه، کوچک‌تر اول)
//   side   : «OURS» (طرفِ «بیمه با ما» در گفتگوی کارکنان و شرکت‌ها)، «CLIENT» (کارمند/شرکت)،
//            یا «STAFF:<id>» در گفتگوی دونفره‌ی همکاران

function chat_state_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT thread FROM chat_state LIMIT 0"); $pdo->query("SELECT viewer FROM chat_hidden LIMIT 0"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
const CHAT_STATE_MSG = 'برای حذف «فقط برای من»، پاک کردنِ تاریخچه و قطعِ گفتگو، مایگریشن migrations/023_chat_hide_close.sql را اجرا کنید.';

function chat_thread_id($type, $id, $meId = 0) {
    if ($type === 'S') { $a = min(intval($id), intval($meId)); $b = max(intval($id), intval($meId)); return "S:$a-$b"; }
    return $type . ':' . intval($id);
}

function chat_state_get($pdo, $thread) {
    if (!chat_state_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT closed_mode, closed_side, closed_by, closed_at FROM chat_state WHERE thread = ? AND closed_mode IS NOT NULL");
    $st->execute([$thread]);
    return $st->fetch() ?: null;
}

// آیا این طرف (side) اجازه‌ی فرستادنِ پیام دارد؟
function chat_side_can_send($pdo, $thread, $side) {
    $s = chat_state_get($pdo, $thread);
    if (!$s) return true;
    if ($s['closed_mode'] === 'BOTH') return false;
    return $s['closed_side'] === $side;   // یک‌طرفه: فقط همان کسی که قطع کرده می‌تواند بفرستد
}

// برای ربات‌ها: کاربرِ کارکنان (تیکت) یا شرکت اجازه‌ی فرستادن دارد؟
function chat_ticket_client_can_send($pdo, $ticketId) {
    if (!chat_state_ready($pdo)) return true;
    $st = $pdo->prepare("SELECT person_id FROM tickets WHERE id = ?");
    $st->execute([intval($ticketId)]);
    $pid = intval($st->fetchColumn());
    return chat_side_can_send($pdo, $pid ? 'P:' . $pid : 'T:' . intval($ticketId), 'CLIENT');
}
function chat_company_can_send($pdo, $companyId, $side = 'CLIENT') {
    return !chat_state_ready($pdo) || chat_side_can_send($pdo, 'C:' . intval($companyId), $side);
}
const CHAT_CLOSED_CLIENT_MSG = 'این گفتگو از طرفِ «بیمه با ما» بسته شده و فعلاً امکانِ فرستادنِ پیام نیست.';
