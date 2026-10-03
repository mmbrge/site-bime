<?php
// فایل: api/_chat_archive.php
// بایگانیِ کاملِ گفتگوها برای مدیر کل: همه‌ی گفتگوهای کارکنان، شرکت‌ها و همکاران (حتی گفتگوی دونفره‌ی دو همکارِ دیگر)
// با پیام‌هایی که حذف شده‌اند (و نامِ حذف‌کننده)، پیام‌های «فقط برای من» پنهان‌شده و تاریخچه‌های پاک‌شده. فقط خواندنی.

function chat_archive_threads($pdo, array $f) {
    chat_access_ensure($pdo);
    $type = $f['type'] ?? '';
    $q = trim((string)($f['q'] ?? ''));
    $out = [];
    $match = fn($hay) => $q === '' || mb_stripos(implode(' ', array_map('strval', $hay)), $q) !== false;
    if ($type === '' || $type === 'P') {
        $rows = $pdo->query("SELECT t.person_id, MIN(t.id) AS tid, COALESCE(p.full_name, MAX(t.sender_name)) AS name, p.national_code, p.mobile_number,
                                    COUNT(tm.id) AS total, SUM(tm.deleted_at IS NOT NULL) AS deleted, MAX(tm.created_at) AS last_at
                               FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id LEFT JOIN persons p ON p.id = t.person_id
                              GROUP BY COALESCE(t.person_id, CONCAT('T', t.id))")->fetchAll();
        foreach ($rows as $r) {
            if (!$match([$r['name'], $r['national_code'], $r['mobile_number']])) continue;
            $out[] = ['key' => $r['person_id'] ? 'P:' . $r['person_id'] : 'T:' . $r['tid'], 'type' => 'PERSON', 'type_fa' => 'کارکنان',
                      'title' => $r['name'] ?: 'کاربر ربات', 'sub' => trim(($r['national_code'] ?? '') . ' ' . ($r['mobile_number'] ?? '')),
                      'total' => (int)$r['total'], 'deleted' => (int)$r['deleted'], 'last_at' => $r['last_at']];
        }
    }
    if ($type === '' || $type === 'C') {
        $rows = $pdo->query("SELECT c.id, c.name, COUNT(m.id) AS total, SUM(m.deleted_at IS NOT NULL) AS deleted, MAX(m.created_at) AS last_at,
                                    GROUP_CONCAT(DISTINCT u.full_name SEPARATOR '، ') AS staff
                               FROM company_chat_messages m JOIN companies c ON c.id = m.company_id LEFT JOIN users u ON u.id = m.sender_user_id
                              GROUP BY c.id")->fetchAll();
        foreach ($rows as $r) {
            if (!$match([$r['name'], $r['staff']])) continue;
            $out[] = ['key' => 'C:' . $r['id'], 'type' => 'COMPANY', 'type_fa' => 'شرکت', 'title' => $r['name'],
                      'sub' => $r['staff'] ? 'همکارانِ پاسخ‌دهنده: ' . $r['staff'] : '', 'total' => (int)$r['total'], 'deleted' => (int)$r['deleted'], 'last_at' => $r['last_at']];
        }
    }
    if ($type === '' || $type === 'S') {
        $rows = $pdo->query("SELECT LEAST(m.from_user_id, m.to_user_id) AS a, GREATEST(m.from_user_id, m.to_user_id) AS b,
                                    COUNT(*) AS total, SUM(m.deleted_at IS NOT NULL) AS deleted, MAX(m.created_at) AS last_at
                               FROM staff_chat_messages m GROUP BY a, b")->fetchAll();
        $names = [];
        foreach ($pdo->query("SELECT id, full_name, role FROM users") as $u) $names[(int)$u['id']] = [$u['full_name'], chat_role_fa($u['role'])];
        foreach ($rows as $r) {
            $na = $names[(int)$r['a']] ?? ['کاربرِ ' . $r['a'], '']; $nb = $names[(int)$r['b']] ?? ['کاربرِ ' . $r['b'], ''];
            if (!$match([$na[0], $nb[0]])) continue;
            $out[] = ['key' => 'S:' . $r['a'] . '-' . $r['b'], 'type' => 'STAFF', 'type_fa' => 'همکاران', 'title' => $na[0] . ' ↔ ' . $nb[0],
                      'sub' => trim($na[1] . ' / ' . $nb[1], ' /'), 'total' => (int)$r['total'], 'deleted' => (int)$r['deleted'], 'last_at' => $r['last_at']];
        }
    }
    $from = !empty($f['from']) && function_exists('fin_jalali_to_date') ? fin_jalali_to_date($f['from']) : null;
    $to = !empty($f['to']) && function_exists('fin_jalali_to_date') ? fin_jalali_to_date($f['to']) : null;
    $out = array_values(array_filter($out, function ($t) use ($from, $to, $f) {
        $d = substr((string)$t['last_at'], 0, 10);
        if ($from && $d < $from) return false;
        if ($to && $d > $to) return false;
        if (!empty($f['deleted_only']) && !$t['deleted']) return false;
        return true;
    }));
    usort($out, fn($a, $b) => strcmp((string)$b['last_at'], (string)$a['last_at']));
    foreach ($out as &$t) { $ts = chat_ts($t['last_at']); $t['last_date'] = chat_jdate($ts); $t['last_time'] = $ts ? date('H:i', $ts) : ''; }
    return $out;
}

// نامِ «بیننده» در chat_hidden (STAFF:3 / PERSON:9 / COMPANY:4 / * = همه)
function chat_archive_viewer_name($pdo, $viewer) {
    static $cache = [];
    if ($viewer === '*') return 'هر دو طرف';
    if (isset($cache[$viewer])) return $cache[$viewer];
    [$k, $id] = array_pad(explode(':', $viewer, 2), 2, 0);
    $sql = ['STAFF' => "SELECT CONCAT(full_name, ' (همکار)') FROM users WHERE id = ?", 'PERSON' => "SELECT CONCAT(full_name, ' (کارمند)') FROM persons WHERE id = ?",
            'COMPANY' => "SELECT CONCAT(full_name, ' (کاربر شرکت)') FROM company_portal_users WHERE id = ?"][$k] ?? null;
    $n = null;
    if ($sql) { try { $st = $pdo->prepare($sql); $st->execute([intval($id)]); $n = $st->fetchColumn(); } catch (Throwable $e) {} }
    return $cache[$viewer] = $n ?: $viewer;
}

function chat_archive_messages($pdo, $key) {
    chat_access_ensure($pdo);
    if (preg_match('/^S:(\d+)-(\d+)$/', (string)$key, $m)) { $type = 'S'; $a = intval($m[1]); $b = intval($m[2]); $actor = ['kind' => 'STAFF', 'id' => $a, 'role' => 'ADMIN']; $id = $b; $thread = "S:$a-$b"; }
    else { [$type, $id] = chat_parse_key($key); if (!$type || $type === 'S') return ['ok' => false, 'error' => 'گفتگو نامعتبر است.']; $actor = ['kind' => 'STAFF', 'id' => 0, 'role' => 'ADMIN']; $thread = chat_thread_id($type, $id); }
    $rows = chat_raw_rows($pdo, $actor, $type, $id);
    $extra = [];
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => intval($r['id']), $rows));
        foreach ($pdo->query("SELECT id, deleted_at, deleted_by, edited_at FROM " . chat_table($type) . " WHERE id IN ($ids)") as $x) $extra[(int)$x['id']] = $x;
    }
    // پنهان‌شده‌ها (حذف «فقط برای من») و پاک‌کردن‌های تاریخچه
    $hidden = []; $clears = [];
    if (chat_state_ready($pdo)) {
        $st = $pdo->prepare("SELECT viewer, msg_id, upto_id, created_at FROM chat_hidden WHERE thread = ? ORDER BY id");
        $st->execute([$thread]);
        foreach ($st->fetchAll() as $h) {
            $who = chat_archive_viewer_name($pdo, $h['viewer']);
            if ($h['msg_id']) $hidden[(int)$h['msg_id']][] = $who;
            if ($h['upto_id']) $clears[] = ['who' => $who, 'upto' => (int)$h['upto_id'], 'date' => chat_jdate(chat_ts($h['created_at'])), 'time' => $h['created_at'] ? date('H:i', strtotime($h['created_at'])) : ''];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $x = $extra[$r['id']] ?? [];
        $hb = $hidden[$r['id']] ?? [];
        foreach ($clears as $c) if ($r['id'] <= $c['upto']) $hb[] = $c['who'] . ' (پاک کردنِ تاریخچه)';
        $out[] = ['id' => $r['id'], 'ours' => $r['ours'], 'sender' => $r['sender'], 'avatar' => $r['avatar'] ?? null, 'text' => $r['text'],
                  'file' => chat_file_out($r['file'], $r['file_name']), 'ref' => $r['ref'], 'edited' => $r['edited'],
                  'deleted' => $r['deleted'], 'deleted_by' => $x['deleted_by'] ?? null,
                  'deleted_date' => !empty($x['deleted_at']) ? chat_jdate(chat_ts($x['deleted_at'])) . ' ' . date('H:i', strtotime($x['deleted_at'])) : null,
                  'hidden_for' => array_values(array_unique($hb)), 'date' => chat_jdate($r['at']), 'time' => $r['at'] ? date('H:i', $r['at']) : ''];
    }
    $state = chat_state_ready($pdo) ? chat_state_get($pdo, $thread) : null;
    return ['ok' => true, 'messages' => $out, 'clears' => $clears, 'state' => $state];
}
