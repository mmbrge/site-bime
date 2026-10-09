<?php
// فایل: api/_name_honor.php
// عنوانِ «آقا / خانم» برای هر کاربرِ پنل (users.honor) و کاربرِ شرکت (company_portal_users.honor): 'M' = آقای، 'F' = خانم.
// نام همه‌جا (پیامِ صبح‌بخیر، سربرگ، گفتگوها، پروفایلِ گفتگو، فهرستِ کاربران) با همین عنوان نوشته می‌شود؛
// اگر خودِ نام با «آقای/خانم/…» شروع شده باشد، دوباره اضافه نمی‌شود. ستون خودکار ساخته می‌شود.

function honor_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['users', 'company_portal_users'] as $t) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE 'honor'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN honor CHAR(1) NULL"); }
        catch (Throwable $e) { error_log('[honor_ensure] ' . $e->getMessage()); }
    }
}
function honor_value($v) { $v = strtoupper(trim((string)$v)); return in_array($v, ['M', 'F'], true) ? $v : null; }
function honor_prefix($h) { return $h === 'M' ? 'آقای ' : ($h === 'F' ? 'خانم ' : ''); }
// «عزیزی» + F => «خانم عزیزی»؛ «خانم عزیزی» همان می‌ماند
function name_with_honor($name, $h) {
    $name = trim((string)$name);
    if ($name === '' || preg_match('/^(?:آقا|آقای|خانم|سرکار|جناب|دکتر|مهندس)(?:\s|$)/u', $name)) return $name;
    return honor_prefix($h) . $name;
}
// عنوانِ همه‌ی کاربرانِ یک نوع (S = پنل، C = شرکت) - یک بار در هر درخواست
function honor_map($pdo, $type) {
    static $m = [];
    if (isset($m[$type])) return $m[$type];
    honor_ensure($pdo);
    $m[$type] = [];
    try {
        foreach ($pdo->query("SELECT id, honor FROM " . ($type === 'C' ? 'company_portal_users' : 'users') . " WHERE honor IS NOT NULL")->fetchAll() as $r) $m[$type][intval($r['id'])] = $r['honor'];
    } catch (Throwable $e) {}
    return $m[$type];
}
function honor_name($pdo, $type, $id, $name) { return name_with_honor($name, honor_map($pdo, $type)[intval($id)] ?? null); }
