<?php
// فایل: api/_chat_access.php
// دسترسیِ گفتگو با شرکت‌ها و بایگانیِ کاملِ گفتگوها (برای مدیر کل)
//  - نقش‌هایی که با شرکت‌ها گفتگو می‌کنند: تنظیمِ chat_company_roles (پیش‌فرض: مدیر کل و همکار بیمه با ما)
//  - در تعریفِ هر پرسنل (users.chat_companies): NULL = طبقِ نقش، 'ALL' = همه‌ی شرکت‌ها، 'NONE' = هیچ، یا JSON شناسه‌ی شرکت‌ها
//  - پیامی که کاربر حذف می‌کند فقط برای خودشان پنهان است؛ مدیر کل در بایگانی همه را با نامِ حذف‌کننده می‌بیند (deleted_by)
// ستون‌ها خودکار ساخته می‌شوند.

function chat_access_ensure($pdo) {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $done = true;
    foreach ([['users', 'chat_companies', 'TEXT NULL'], ['ticket_messages', 'deleted_by', 'VARCHAR(160) NULL'],
              ['company_chat_messages', 'deleted_by', 'VARCHAR(160) NULL'], ['staff_chat_messages', 'deleted_by', 'VARCHAR(160) NULL']] as [$t, $c, $def]) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (Throwable $e) { error_log('[chat_access_ensure] ' . $e->getMessage()); }
    }
}

const CHAT_COMPANY_ROLE_OPTIONS = ['OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما', 'PARSIAN' => 'همکار · پنل پارسیان', 'LIFE' => 'همکار · بیمه عمر'];

function chat_company_roles($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $v = null;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'chat_company_roles'");
        $st->execute();
        $v = $st->fetchColumn();
    } catch (Throwable $e) {}
    $roles = ($v === false || $v === null) ? ['COMPANY_LIAISON'] : array_values(array_filter(explode(',', (string)$v)));
    return $cache = array_values(array_unique(array_merge(['ADMIN'], $roles)));
}
function chat_set_company_roles($pdo, array $roles) {
    $roles = array_values(array_intersect($roles, array_keys(CHAT_COMPANY_ROLE_OPTIONS)));
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('chat_company_roles', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([implode(',', $roles)]);
}

// مقدارِ ورودیِ فرمِ پرسنل → مقدارِ ستون
function chat_companies_value($in) {
    if (is_array($in)) { $ids = array_values(array_unique(array_filter(array_map('intval', $in)))); return $ids ? json_encode($ids) : 'NONE'; }
    $in = trim((string)$in);
    if ($in === '' || $in === 'ROLE') return null;
    if ($in === 'ALL' || $in === 'NONE') return $in;
    $j = json_decode($in, true);
    if (is_array($j)) return chat_companies_value($j);
    return null;
}
// شکلِ خوانا برای فرم: ['mode' => ROLE|ALL|NONE|LIST, 'ids' => [...]]
function chat_companies_decode($v) {
    if ($v === null || $v === '') return ['mode' => 'ROLE', 'ids' => []];
    if ($v === 'ALL' || $v === 'NONE') return ['mode' => $v, 'ids' => []];
    $j = json_decode((string)$v, true);
    return is_array($j) ? ['mode' => 'LIST', 'ids' => array_map('intval', $j)] : ['mode' => 'ROLE', 'ids' => []];
}

// شرکت‌هایی که این کاربرِ پنل می‌تواند با آن‌ها گفتگو کند: 'ALL' | [id, ...] | null (هیچ)
function chat_company_scope($pdo, $actor) {
    static $cache = [];
    if (($actor['kind'] ?? '') !== 'STAFF') return null;
    $uid = intval($actor['id']);
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    if (($actor['role'] ?? '') === 'ADMIN') return $cache[$uid] = 'ALL';
    chat_access_ensure($pdo);
    $v = null;
    try { $st = $pdo->prepare("SELECT chat_companies FROM users WHERE id = ?"); $st->execute([$uid]); $v = $st->fetchColumn(); } catch (Throwable $e) {}
    $d = chat_companies_decode($v === false ? null : $v);
    if ($d['mode'] === 'ALL') return $cache[$uid] = 'ALL';
    if ($d['mode'] === 'NONE') return $cache[$uid] = null;
    if ($d['mode'] === 'LIST') return $cache[$uid] = $d['ids'] ?: null;
    return $cache[$uid] = in_array($actor['role'] ?? '', chat_company_roles($pdo), true) ? 'ALL' : null;
}
function chat_scope_allows($scope, $companyId) {
    return $scope === 'ALL' || (is_array($scope) && in_array(intval($companyId), $scope, true));
}
// شرطِ SQL برای ستونِ شرکت
function chat_scope_sql($scope, $col) {
    if ($scope === 'ALL') return '1=1';
    if (is_array($scope) && $scope) return "$col IN (" . implode(',', array_map('intval', $scope)) . ")";
    return '1=0';
}
// نامِ کسی که کاری کرده (برای «حذف‌شده توسطِ ...»)
function chat_actor_label($actor) {
    $k = ['STAFF' => 'همکار', 'PERSON' => 'کارمند', 'COMPANY' => 'کاربر شرکت'][$actor['kind'] ?? ''] ?? '';
    return mb_substr(trim(($actor['name'] ?? '') . ($k ? ' (' . $k . ')' : '')), 0, 160);
}
