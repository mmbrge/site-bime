<?php
// فایل: api/_psg_drafts.php
// «سایت بیمه‌گر / پاسارگاد / پیش‌نویس‌ها»: فهرستِ کاملِ پیش‌نویس‌های ثالث و بدنه از سامانه‌ی پاسارگاد (با همان نشستِ سرور که استعلام‌ها دارند).
//   - جستجو با «تاریخ صدور از …» (پیش‌فرض ۱۴۰۳/۰۱/۰۱؛ بدونِ آن سامانه فقط ۲۰ روزِ اخیر را نشان می‌دهد)
//   - صفحه‌به‌صفحه (هر صفحه ۱۰ ردیف) با «بعدی»؛ هر فراخوانیِ sync_step چند صفحه، تا مرورگر پیشرفت را ببیند و روی هر هاستی کار کند
//   - فقط خواندن؛ عملیات روی پیش‌نویس‌ها در فازِ بعد. نام‌کاربری/رمز و کوکیِ سامانه فقط روی سرور است و به مرورگر نمی‌رود.
// نیاز: _inquiry.php (InqPasargad، inq_lock، inq_dir، inq_px_mark).

const PSGD_KINDS = [
    'third' => ['label' => 'ثالث', 'list' => '/cins/bimehShakhsSalesPishnevis.list.html', 'post' => '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simplelist.html'],
    'body'  => ['label' => 'بدنه', 'list' => '/cins/bimehBadanehPishnevis.list.html', 'post' => '/cins/bimehbadanehpishnevis/bimehBadanehPishnevis.simplelist.html'],
];
const PSGD_PAGES_PER_STEP = 4;
const PSGD_MAX_PAGES = 600;

function psgd_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS psg_drafts (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(6) NOT NULL, draft_no VARCHAR(60) NOT NULL, insured VARCHAR(190) NULL, state VARCHAR(60) NULL,
            created_j VARCHAR(24) NULL, start_j VARCHAR(12) NULL, end_j VARCHAR(12) NULL, car VARCHAR(190) NULL, plate VARCHAR(60) NULL, car_usage VARCHAR(100) NULL,
            engine_no VARCHAR(60) NULL, chassis_no VARCHAR(60) NULL, f1 VARCHAR(120) NULL, f2 VARCHAR(190) NULL, f3 VARCHAR(190) NULL,
            premium BIGINT NULL, net BIGINT NULL, unit VARCHAR(190) NULL, supervisor VARCHAR(190) NULL, user_name VARCHAR(190) NULL,
            sync_id INT NULL, stale TINYINT(1) NOT NULL DEFAULT 0, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,
            UNIQUE KEY uq_kind_no (kind, draft_no), KEY idx_kind_stale (kind, stale))$T",
        "CREATE TABLE IF NOT EXISTS psg_draft_syncs (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(6) NOT NULL, from_j VARCHAR(10) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'RUNNING',
            pages INT NOT NULL DEFAULT 0, rows_seen INT NOT NULL DEFAULT 0, rows_new INT NOT NULL DEFAULT 0, last_first VARCHAR(60) NULL, message VARCHAR(600) NULL,
            user_id INT NULL, user_name VARCHAR(190) NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, KEY idx_kind (kind, id))$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[psgd_ensure] ' . $e->getMessage()); } }
}

function psgd_dir($sub = '') { $d = inq_dir('psg_drafts' . ($sub !== '' ? '/' . $sub : '')); if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }

// «1403/01/01» (با رقمِ فارسی هم) => [y, m, d] یا null
function psgd_date($s) {
    $s = strtr(trim((string)$s), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    if (!preg_match('/^(1[34]\d{2})\D(\d{1,2})\D(\d{1,2})$/', $s, $m)) return null;
    if ($m[2] < 1 || $m[2] > 12 || $m[3] < 1 || $m[3] > 31) return null;
    return [sprintf('%04d', $m[1]), sprintf('%02d', $m[2]), sprintf('%02d', $m[3])];
}
function psgd_money($s) { $d = preg_replace('/\D/', '', strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])); return $d === '' ? null : (int)$d; }

// یک خانه => خطوط (با <br>)
function psgd_lines($td) {
    $td = preg_replace('/<script\b.*?<\/script>/is', '', $td);
    $parts = preg_split('/<br\s*\/?>/i', $td);
    return array_map(function ($p) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES, 'UTF-8'))); }, $parts);
}

// ردیف‌های جدولِ نتیجه. ستون‌ها (از خانه‌ی شماره پیش‌نویس به بعد):
//   ثالث: [شماره/بیمه‌گذار/مرحله] [صدور/شروع/انقضا] [خودرو/پلاک/کاربری] [موتور/شاسی] [تعهد مالی/جانی/حوادث] [حق بیمه/خالص] [واحد/ناظر/کاربر]
//   بدنه: [شماره/بیمه‌گذار/مرحله] [صدور/شروع/انقضا] [ارزش/موافقت‌نامه/نماینده] [خودرو/پلاک/کارشناسی] [موتور/شاسی/کاربری] [حق بیمه/خالص] [واحد/ناظر/کاربر]
function psgd_parse_rows($html, $kind) {
    $rows = [];
    // لنگر: خانه‌ی «شماره پیش‌نویس» (span با dir=ltr و الگوی 1/49357/1405/523)؛ شش خانه‌ی بعدی مالِ همان ردیف‌اند
    if (!preg_match_all('#<td\b[^>]*>\s*<span dir="ltr"[^>]*>\s*\d+/\d+/1[34]\d{2}/\d+\s*</span>#', $html, $m, PREG_OFFSET_CAPTURE)) return $rows;
    $pos = array_map(function ($x) { return $x[1]; }, $m[0]);
    $end = false;
    foreach (['dataTablefirst', 'dataTableprevious', 'class="pageNum"'] as $mk) { $p = strpos($html, $mk, end($pos)); if ($p !== false && ($end === false || $p < $end)) $end = $p; }
    if ($end === false) $end = strlen($html);
    foreach ($pos as $i => $st) {
        $en = $pos[$i + 1] ?? $end;
        $seg = substr($html, $st, $en - $st);
        $tds = array_values(array_filter(preg_split('/(?=<td\b)/i', $seg), function ($t) { return stripos($t, '<td') === 0; }));
        if (count($tds) < 7) continue;
        $c = []; for ($q = 0; $q < 7; $q++) $c[] = psgd_lines($tds[$q]);
        $g = function ($ci, $li) use ($c) { return (string)($c[$ci][$li] ?? ''); };
        $r = ['draft_no' => $g(0, 0), 'insured' => $g(0, 1), 'state' => $g(0, 2), 'created_j' => $g(1, 0), 'start_j' => $g(1, 1), 'end_j' => $g(1, 2),
              'premium' => psgd_money($g(5, 0)), 'net' => psgd_money($g(5, 1)), 'unit' => $g(6, 0), 'supervisor' => $g(6, 1), 'user_name' => $g(6, 2)];
        if ($kind === 'body') $r += ['f1' => $g(2, 0), 'f2' => $g(2, 1), 'f3' => $g(2, 2), 'car' => $g(3, 0), 'plate' => $g(3, 1), 'expert' => $g(3, 2), 'engine_no' => $g(4, 0), 'chassis_no' => $g(4, 1), 'car_usage' => $g(4, 2)];
        else $r += ['car' => $g(2, 0), 'plate' => $g(2, 1), 'car_usage' => $g(2, 2), 'engine_no' => $g(3, 0), 'chassis_no' => $g(3, 1), 'f1' => $g(4, 0), 'f2' => $g(4, 1), 'f3' => $g(4, 2)];
        if ($r['draft_no'] !== '') $rows[] = $r;
    }
    return $rows;
}
// صفحه‌ی فعلی و آخرین شماره‌ی صفحه‌ای که در صفحه‌شمار دیده می‌شود
function psgd_pager($html) {
    $cur = preg_match('/class="currentPage"[^>]*>\s*<a[^>]*>\s*(\d+)\s*</', $html, $m) ? (int)$m[1] : 1;
    $max = $cur;
    if (preg_match_all('/dataTableidx(\d+)/', $html, $mm)) $max = max($max, max(array_map('intval', $mm[1])));
    return [$cur, $max];
}

class PsgDrafts extends InqPasargad
{
    public function __construct($jobDir = '') { parent::__construct($jobDir); }
    public function isLoggedOut($html) { return $this->loggedOut($html); }

    // صفحه‌ی اول با فیلترِ تاریخ => ['ok', 'html'] | ['need_login'] | ['error']
    public function search($kind, array $ymd)
    {
        $k = PSGD_KINDS[$kind];
        $r = $this->http('GET', $this->core . $k['list']);
        if ($r['body'] === '' && $r['error'] !== '') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست (' . $r['error'] . ').'];
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'صفحه‌ی پیش‌نویس‌ها باز نشد (شاید دسترسیِ این کاربر در سامانه‌ی پاسارگاد بسته است).'];
        [$y, $m, $d] = $ymd;
        $ov = ['exampleform:tarikhSodoor_ge' => "$y/$m/$d;HIJRI", 'exampleform:tarikhSodoor_ge_y' => $y, 'exampleform:tarikhSodoor_ge_m' => $m, 'exampleform:tarikhSodoor_ge_d' => $d,
               'exampleform:fieldset:dataTable:goto' => '1'];
        $r2 = $this->submit($this->core . $k['post'], $r['body'], $ov, 'exampleform:search', $this->core . $k['list']);
        if ($this->loggedOut($r2['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r2['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'جستجو در سامانه انجام نشد' . ($r2['error'] ? ' (' . $r2['error'] . ')' : '') . '.'];
        return ['ok' => true, 'html' => $r2['body']];
    }
    // صفحه‌ی بعد (از روی صفحه‌ی فعلی)
    public function next($kind, $html)
    {
        $k = PSGD_KINDS[$kind];
        $r = $this->submit($this->core . $k['post'], $html, ['exampleform:fieldset:dataTable' => 'next'], 'exampleform:fieldset:dataTable:dataTablenext', $this->core . $k['post']);
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'صفحه‌ی بعد خوانده نشد' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '.'];
        return ['ok' => true, 'html' => $r['body']];
    }
}

function psgd_save_rows($pdo, $kind, $syncId, array $rows) {
    $new = 0;
    $cols = ['insured', 'state', 'created_j', 'start_j', 'end_j', 'car', 'plate', 'car_usage', 'engine_no', 'chassis_no', 'f1', 'f2', 'f3', 'premium', 'net', 'unit', 'supervisor', 'user_name'];
    $ex = $pdo->prepare("SELECT id FROM psg_drafts WHERE kind = ? AND draft_no = ?");
    $ins = $pdo->prepare("INSERT INTO psg_drafts (kind, draft_no, " . implode(', ', $cols) . ", sync_id, stale, first_seen, last_seen) VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, 0, NOW(), NOW())");
    $upd = $pdo->prepare("UPDATE psg_drafts SET " . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ", sync_id = ?, stale = 0, last_seen = NOW() WHERE id = ?");
    foreach ($rows as $r) {
        $vals = [];
        foreach ($cols as $c) { $v = $r[$c] ?? null; $vals[] = is_string($v) ? (mb_substr($v, 0, 190) ?: null) : $v; }
        if ($kind === 'body' && !empty($r['expert'])) $vals[array_search('f3', $cols, true)] = mb_substr(trim(($r['f3'] ?? '') . ' · کارشناسی ' . $r['expert'], ' ·'), 0, 190);
        $ex->execute([$kind, $r['draft_no']]);
        if ($id = $ex->fetchColumn()) $upd->execute(array_merge($vals, [$syncId, $id]));
        else { $ins->execute(array_merge([$kind, $r['draft_no']], $vals, [$syncId])); $new++; }
    }
    return $new;
}

function psgd_jdt($dt) {
    if (!$dt) return null;
    $ts = strtotime($dt);
    if (!function_exists('jalali_from_gregorian_ts_dotted')) require_once __DIR__ . '/_case_helpers.php';
    return str_replace('.', '/', jalali_from_gregorian_ts_dotted($ts)) . ' ' . date('H:i', $ts);
}
function psgd_sync_get($pdo, $id) { $st = $pdo->prepare("SELECT * FROM psg_draft_syncs WHERE id = ?"); $st->execute([intval($id)]); return $st->fetch() ?: null; }
function psgd_sync_out($s) {
    return $s ? ['id' => (int)$s['id'], 'kind' => $s['kind'], 'from' => $s['from_j'], 'status' => $s['status'], 'pages' => (int)$s['pages'], 'rows' => (int)$s['rows_seen'],
                 'new' => (int)$s['rows_new'], 'message' => $s['message'], 'by' => $s['user_name'], 'at' => psgd_jdt($s['started_at']), 'done_at' => psgd_jdt($s['finished_at'])] : null;
}

// شروع: صفحه‌ی اولِ جستجو
function psgd_sync_start($pdo, $kind, $from, array $actor) {
    psgd_ensure($pdo);
    if (!isset(PSGD_KINDS[$kind])) return ['ok' => false, 'error' => 'نوعِ پیش‌نویس نامعتبر است.'];
    $ymd = psgd_date($from);
    if (!$ymd) return ['ok' => false, 'error' => 'تاریخ را مثلِ ۱۴۰۳/۰۱/۰۱ بنویسید.'];
    $lk = inq_lock(60);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد مشغولِ کارِ دیگری (استعلام) است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    try {
        @set_time_limit(180);
        $c = new PsgDrafts();
        $r = $c->search($kind, $ymd);
    } catch (Throwable $e) { $r = ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
    if (!empty($r['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'برای خواندن از سامانه‌ی پاسارگاد اول وارد شوید (کدِ تصویر).']; }
    if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'خطا'];
    inq_px_mark('logged_in'); if (function_exists('inq_touch_activity')) inq_touch_activity();
    $pdo->prepare("INSERT INTO psg_draft_syncs (kind, from_j, status, user_id, user_name, started_at) VALUES (?, ?, 'RUNNING', ?, ?, NOW())")
        ->execute([$kind, implode('/', $ymd), $actor['id'] ?: null, mb_substr((string)$actor['name'], 0, 190)]);
    $sid = (int)$pdo->lastInsertId();
    return psgd_take_page($pdo, $kind, $sid, $r['html']);
}
// یک صفحه را ثبت می‌کند و تصمیم می‌گیرد ادامه دارد یا نه
function psgd_take_page($pdo, $kind, $sid, $html) {
    $s = psgd_sync_get($pdo, $sid);
    $rows = psgd_parse_rows($html, $kind);
    [$cur, $max] = psgd_pager($html);
    $first = $rows[0]['draft_no'] ?? '';
    $repeat = $first !== '' && $first === (string)$s['last_first'] && (int)$s['pages'] > 0;
    $new = $repeat ? 0 : psgd_save_rows($pdo, $kind, $sid, $rows);
    $pages = (int)$s['pages'] + ($repeat ? 0 : 1);
    $done = !$rows || $repeat || $cur >= $max || count($rows) < 10 || $pages >= PSGD_MAX_PAGES;
    $pdo->prepare("UPDATE psg_draft_syncs SET pages = ?, rows_seen = rows_seen + ?, rows_new = rows_new + ?, last_first = ? WHERE id = ?")
        ->execute([$pages, $repeat ? 0 : count($rows), $new, $first ?: null, $sid]);
    if ($done) psgd_sync_finish($pdo, $kind, $sid);
    else @file_put_contents(psgd_dir() . '/sync_' . $sid . '.html', $html);
    return ['ok' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid)), 'done' => $done];
}
// پایان: پیش‌نویسی که در این بازه بود و دیگر در سامانه نیست (ثبت/حذف شده) => «قدیمی»
function psgd_sync_finish($pdo, $kind, $sid, $status = 'DONE', $msg = null) {
    $s = psgd_sync_get($pdo, $sid);
    if ($status === 'DONE' && $s) {
        try {
            $from = str_replace('/', '', (string)$s['from_j']);
            $pdo->prepare("UPDATE psg_drafts SET stale = 1 WHERE kind = ? AND (sync_id IS NULL OR sync_id <> ?) AND REPLACE(LEFT(created_j, 10), '/', '') >= ?")->execute([$kind, $sid, $from]);
        } catch (Throwable $e) { error_log('[psgd stale] ' . $e->getMessage()); }
    }
    $pdo->prepare("UPDATE psg_draft_syncs SET status = ?, message = ?, finished_at = NOW() WHERE id = ?")->execute([$status, $msg ? mb_substr($msg, 0, 600) : null, $sid]);
    @unlink(psgd_dir() . '/sync_' . $sid . '.html');
}
// ادامه: چند صفحه‌ی دیگر
function psgd_sync_step($pdo, $sid) {
    psgd_ensure($pdo);
    $s = psgd_sync_get($pdo, $sid);
    if (!$s) return ['ok' => false, 'error' => 'بروزرسانی پیدا نشد.'];
    if ($s['status'] !== 'RUNNING') return ['ok' => true, 'sync' => psgd_sync_out($s), 'done' => true];
    $file = psgd_dir() . '/sync_' . (int)$sid . '.html';
    $html = is_file($file) ? (string)file_get_contents($file) : '';
    if ($html === '') { psgd_sync_finish($pdo, $s['kind'], $sid, 'FAIL', 'صفحه‌ی قبلی پیدا نشد؛ دوباره «بروزرسانی» بزنید.'); return ['ok' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid)), 'done' => true]; }
    $lk = inq_lock(60);
    if (!$lk) return ['ok' => true, 'sync' => psgd_sync_out($s), 'done' => false, 'busy' => true];
    $out = null;
    try {
        @set_time_limit(240);
        $c = new PsgDrafts();
        for ($i = 0; $i < PSGD_PAGES_PER_STEP; $i++) {
            $r = $c->next($s['kind'], $html);
            if (!empty($r['need_login'])) { inq_px_mark('logged_out'); psgd_sync_finish($pdo, $s['kind'], $sid, 'LOGIN', 'نشستِ سامانه بسته شد؛ وارد شوید و دوباره «بروزرسانی» بزنید.'); $out = ['ok' => false, 'need_login' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid))]; break; }
            if (empty($r['ok'])) { psgd_sync_finish($pdo, $s['kind'], $sid, 'FAIL', $r['error'] ?? 'خطا'); $out = ['ok' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid)), 'done' => true]; break; }
            $html = $r['html'];
            $out = psgd_take_page($pdo, $s['kind'], $sid, $html);
            if ($out['done']) break;
        }
    } catch (Throwable $e) {
        psgd_sync_finish($pdo, $s['kind'], $sid, 'FAIL', 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160));
        $out = ['ok' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid)), 'done' => true];
    } finally { inq_unlock($lk); }
    return $out ?: ['ok' => true, 'sync' => psgd_sync_out(psgd_sync_get($pdo, $sid)), 'done' => false];
}

function psgd_list($pdo, $kind, $withStale = false) {
    psgd_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM psg_drafts WHERE kind = ?" . ($withStale ? '' : " AND stale = 0") . " ORDER BY created_j DESC, id DESC LIMIT 20000");
    $st->execute([$kind]);
    $rows = array_map(function ($r) {
        return ['id' => (int)$r['id'], 'no' => $r['draft_no'], 'insured' => $r['insured'], 'state' => $r['state'], 'created' => $r['created_j'], 'start' => $r['start_j'], 'end' => $r['end_j'],
                'car' => $r['car'], 'plate' => $r['plate'], 'usage' => $r['car_usage'], 'engine' => $r['engine_no'], 'chassis' => $r['chassis_no'],
                'f1' => $r['f1'], 'f2' => $r['f2'], 'f3' => $r['f3'], 'premium' => $r['premium'] !== null ? (int)$r['premium'] : null, 'net' => $r['net'] !== null ? (int)$r['net'] : null,
                'unit' => $r['unit'], 'supervisor' => $r['supervisor'], 'user' => $r['user_name'], 'stale' => (bool)$r['stale'], 'seen' => $r['last_seen']];
    }, $st->fetchAll());
    $st = $pdo->prepare("SELECT * FROM psg_draft_syncs WHERE kind = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$kind]);
    return ['rows' => $rows, 'last_sync' => psgd_sync_out($st->fetch() ?: null)];
}
