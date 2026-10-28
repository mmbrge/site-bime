<?php
// فایل: api/vehicle_catalog.php
// خواندنِ فهرستِ وسایلِ نقلیه برای مینی‌اپ (بی‌نیاز از ورود) + مدیریت از پنل (فقط مدیر کل)
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_vehicle_catalog.php';

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? ($_POST['action'] ?? ''));
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

try {
    vc_ensure($pdo);
    // ---------------- خواندن (عمومی) ----------------
    if ($action === 'kinds') $out(['ok' => true, 'kinds' => vc_kinds_list()]);
    if ($action === 'groups') {
        $kind = (string)($_GET['kind'] ?? '');
        if (!isset(VC_KINDS[$kind])) $out(['ok' => false, 'error' => 'نوعِ وسیله نامعتبر است.']);
        $out(['ok' => true, 'groups' => vc_groups($pdo, $kind)]);
    }
    if ($action === 'models') {
        $kind = (string)($_GET['kind'] ?? '');
        if (!isset(VC_KINDS[$kind])) $out(['ok' => false, 'error' => 'نوعِ وسیله نامعتبر است.']);
        $rows = vc_models($pdo, $kind, (string)($_GET['grp'] ?? ''), (string)($_GET['q'] ?? ''), min(800, max(20, intval($_GET['limit'] ?? 600))));
        $out(['ok' => true, 'models' => array_map(fn($r) => ['id' => (int)$r['id'], 'grp' => $r['grp'], 'title' => $r['title'], 'sub' => vc_sub($r), 'rate' => $r['premium_cat'] !== null ? (int)$r['premium_cat'] : null], $rows)]);
    }

    if ($action === 'vsearch') {
        $kind = (string)($_GET['kind'] ?? '');
        if (!isset(VC_KINDS[$kind])) $out(['ok' => false, 'error' => 'نوعِ وسیله نامعتبر است.']);
        $r = vc_search($pdo, $kind, (string)($_GET['q'] ?? ''), (string)($_GET['brand'] ?? ''));
        if (isset($r['brands'])) $out(['ok' => true, 'brands' => $r['brands']]);
        $out(['ok' => true, 'total' => $r['total'], 'models' => array_map(fn($m) => ['id' => (int)$m['id'], 'grp' => $m['grp'], 'brand' => $m['brand'], 'title' => $m['title'],
            'sub' => vc_sub($m), 'rate' => $m['premium_cat'] !== null ? (int)$m['premium_cat'] : null], $r['models'])]);
    }

    // ---------------- مدیریت (پنل) ----------------
    session_start();
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);

    if ($action === 'admin_list') {
        $kind = (string)($in['kind'] ?? '');
        $w = ['1=1']; $a = [];
        if (isset(VC_KINDS[$kind])) { $w[] = 'kind = ?'; $a[] = $kind; }
        if (($in['grp'] ?? '') !== '') { $w[] = 'grp = ?'; $a[] = (string)$in['grp']; }
        $q = vc_norm($in['q'] ?? '');
        if ($q !== '') { $w[] = "(REPLACE(REPLACE(title, 'ي', 'ی'), 'ك', 'ک') LIKE ? OR code = ?)"; $a[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; $a[] = $q; }
        if (($in['rate'] ?? '') === 'none') $w[] = 'premium_cat IS NULL';
        if (($in['rate'] ?? '') === 'has') $w[] = 'premium_cat IS NOT NULL';
        $page = max(1, intval($in['page'] ?? 1)); $per = 50;
        $st = $pdo->prepare("SELECT COUNT(*) FROM vehicle_catalog WHERE " . implode(' AND ', $w)); $st->execute($a);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM vehicle_catalog WHERE " . implode(' AND ', $w) . " ORDER BY kind, grp, title LIMIT " . (($page - 1) * $per) . ", $per");
        $st->execute($a);
        $groups = $pdo->query("SELECT kind, grp, COUNT(*) n, SUM(active) act, MIN(premium_cat) mn, MAX(premium_cat) mx, SUM(premium_cat IS NULL) nonull FROM vehicle_catalog GROUP BY kind, grp ORDER BY kind, MIN(id)")->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/premium_calc.php';
        $cats = []; foreach (premium_vehicle_categories() as $c => $v) $cats[] = ['code' => $c, 'name' => $v['name']];
        $out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per' => $per, 'groups' => $groups, 'kinds' => vc_kinds_list(), 'premium_cats' => $cats]);
    }
    // خروجیِ اکسل: kind = یک نوع (همه‌ی مدل‌هایش به تفکیکِ گروه) یا ALL = کتابِ کلی (شیتِ خلاصه + یک شیت برای هر نوع: سواری، بارکش، اتوکار، …)
    if ($action === 'admin_export') {
        require_once __DIR__ . '/_xlsx_writer.php';
        require_once __DIR__ . '/premium_calc.php';
        $kind = (string)($_GET['kind'] ?? 'ALL');
        $kinds = $kind === 'ALL' ? array_keys(VC_KINDS) : (isset(VC_KINDS[$kind]) ? [$kind] : []);
        if (!$kinds) $out(['ok' => false, 'error' => 'نوعِ وسیله نامعتبر است.']);
        $cats = premium_vehicle_categories();
        $hdr = ['ردیف', 'گروه', 'مدل', 'برند / سازنده', 'تعداد سیلندر', 'سرنشین (نفر)', 'تناژ (تن)', 'کد رایانه', 'رده‌ی استعلامِ ثالث', 'وضعیت'];
        $st = $pdo->prepare("SELECT * FROM vehicle_catalog WHERE kind = ? ORDER BY grp, title");
        $sheets = []; $sum = [];
        foreach ($kinds as $k) {
            $st->execute([$k]);
            $rows = []; $shade = []; $prev = null; $band = false; $groups = []; $active = 0; $online = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
                if ($r['grp'] !== $prev) { $band = !$band; $prev = $r['grp']; }
                if ($band) $shade[] = $i;
                $groups[$r['grp']] = 1; $active += (int)$r['active']; $online += $r['premium_cat'] !== null ? 1 : 0;
                $ton = $r['tonnage'] !== null && (float)$r['tonnage'] > 0 ? rtrim(rtrim((string)$r['tonnage'], '0'), '.') : '';
                $rows[] = [$i + 1, $r['grp'], $r['title'], vc_brand($k, $r['title']), $r['cylinders'] ?: '', $r['seats'] ?: '', $ton, $r['code'] ?? '',
                           $r['premium_cat'] !== null ? ($cats[(int)$r['premium_cat']]['name'] ?? (string)$r['premium_cat']) : 'بدونِ استعلام (ارجاع به کارشناس)', $r['active'] ? 'فعال' : 'غیرفعال'];
            }
            $fa = VC_KINDS[$k]['fa'];
            $sum[] = [$fa, count($rows), count($groups), $active, $online];
            $sheets[] = ['name' => $fa, 'title' => 'فهرستِ ' . $fa . ' (' . count($rows) . ' مدل در ' . count($groups) . ' گروه)', 'headers' => $hdr, 'rows' => $rows, 'numeric' => [0, 4, 5], 'shade' => $shade];
        }
        if ($kind === 'ALL') {
            $tot = [0, 0, 0, 0];
            foreach ($sum as $r) for ($j = 0; $j < 4; $j++) $tot[$j] += $r[$j + 1];
            $sum[] = array_merge(['جمع'], $tot);
            array_unshift($sheets, ['name' => 'خلاصه', 'title' => 'انواعِ وسیله‌ی نقلیه — هر نوع در شیتِ جدای خودش', 'headers' => ['نوع', 'تعداد مدل', 'تعداد گروه', 'فعال', 'دارای استعلامِ آنلاین'],
                                   'rows' => $sum, 'numeric' => [1, 2, 3, 4], 'shade' => [count($sum) - 1]]);
        }
        $path = xlsx_build_book($sheets);
        xlsx_send($path, ($kind === 'ALL' ? 'انواع وسیله نقلیه (همه)' : 'انواع وسیله نقلیه - ' . VC_KINDS[$kind]['fa']) . ' ' . (function_exists('jalali_from_gregorian_ts_dotted') ? jalali_from_gregorian_ts_dotted(time()) : date('Y.m.d')) . '.xlsx');
        exit;
    }
    // ورودِ تیپ‌های تازه از بایگانیِ بردرو: step=preview (فهرستِ مدل‌های نبوده) یا save (ثبتِ انتخاب‌شده‌ها)
    if ($action === 'admin_brd_import') {
        if (($in['step'] ?? 'preview') === 'save') $out(vc_insert_items($pdo, is_array($in['items'] ?? null) ? $in['items'] : []));
        $out(vc_bordereau_candidates($pdo));
    }
    if ($action === 'admin_save') {
        $id = intval($in['id'] ?? 0);
        $kind = (string)($in['kind'] ?? '');
        $grp = mb_substr(vc_norm($in['grp'] ?? ''), 0, 120);
        $title = mb_substr(vc_norm($in['title'] ?? ''), 0, 190);
        if (!isset(VC_KINDS[$kind]) || $grp === '' || $title === '') $out(['ok' => false, 'error' => 'نوع، گروه و نامِ مدل را کامل کنید.']);
        $num = fn($k) => ($in[$k] ?? '') === '' ? null : (0 + $in[$k]);
        $cat = ($in['premium_cat'] ?? '') === '' ? null : intval($in['premium_cat']);
        $vals = [$kind, $grp, $title, $num('cylinders'), $num('seats'), $num('tonnage'), trim((string)($in['code'] ?? '')) ?: null, $cat, !empty($in['active']) || !array_key_exists('active', $in) ? 1 : 0];
        if ($id) $pdo->prepare("UPDATE vehicle_catalog SET kind=?, grp=?, title=?, cylinders=?, seats=?, tonnage=?, code=?, premium_cat=?, active=? WHERE id=?")->execute(array_merge($vals, [$id]));
        else { $pdo->prepare("INSERT INTO vehicle_catalog (kind, grp, title, cylinders, seats, tonnage, code, premium_cat, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($vals); $id = (int)$pdo->lastInsertId(); }
        $out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'admin_delete') {
        $pdo->prepare("DELETE FROM vehicle_catalog WHERE id = ?")->execute([intval($in['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    // رده‌ی استعلام / فعال‌بودنِ یک گروهِ کامل
    if ($action === 'admin_group') {
        $kind = (string)($in['kind'] ?? ''); $grp = (string)($in['grp'] ?? '');
        if (!isset(VC_KINDS[$kind]) || $grp === '') $out(['ok' => false, 'error' => 'گروه نامعتبر است.']);
        if (array_key_exists('premium_cat', $in)) {
            $cat = ($in['premium_cat'] ?? '') === '' ? null : intval($in['premium_cat']);
            $pdo->prepare("UPDATE vehicle_catalog SET premium_cat = ? WHERE kind = ? AND grp = ?")->execute([$cat, $kind, $grp]);
        }
        if (array_key_exists('active', $in)) $pdo->prepare("UPDATE vehicle_catalog SET active = ? WHERE kind = ? AND grp = ?")->execute([!empty($in['active']) ? 1 : 0, $kind, $grp]);
        if (($in['rename'] ?? '') !== '') $pdo->prepare("UPDATE vehicle_catalog SET grp = ? WHERE kind = ? AND grp = ?")->execute([mb_substr(vc_norm($in['rename']), 0, 120), $kind, $grp]);
        $out(['ok' => true]);
    }
    // ورود از اکسل (همان قالبِ فهرستِ بیمه‌گر: ردیف، عنوان، شرح، تناژ، سرنشین، سیلندر، کد رایانه، گروه تعرفه) برای یک نوع
    if ($action === 'admin_import') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!isset(VC_KINDS[$kind])) $out(['ok' => false, 'error' => 'نوعِ وسیله را انتخاب کنید.']);
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) $out(['ok' => false, 'error' => 'فایلی دریافت نشد.']);
        require_once __DIR__ . '/finance_core.php';
        $rows = fin_read_xlsx_fallback($_FILES['file']['tmp_name']) ?: [];
        if (!$rows) $out(['ok' => false, 'error' => 'اکسل خوانده نشد.']);
        $ex = $pdo->prepare("SELECT id FROM vehicle_catalog WHERE kind = ? AND title = ?");
        $ins = $pdo->prepare("INSERT INTO vehicle_catalog (kind, grp, title, cylinders, seats, tonnage, code) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $add = 0; $skip = 0;
        foreach (array_slice($rows, 1) as $r) {
            $title = vc_norm($r[1] ?? ''); if ($title === '') continue;
            $ex->execute([$kind, $title]); if ($ex->fetchColumn()) { $skip++; continue; }
            $grp = vc_norm($r[7] ?? '') ?: vc_norm($r[2] ?? '') ?: 'سایر';
            $n = fn($v) => is_numeric($v) ? 0 + $v : null;
            $ins->execute([$kind, mb_substr($grp, 0, 120), mb_substr($title, 0, 190), $n($r[5] ?? null), $n($r[4] ?? null), $n($r[3] ?? null), trim((string)($r[6] ?? '')) ?: null]);
            $add++;
        }
        $out(['ok' => true, 'added' => $add, 'skipped' => $skip]);
    }
    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[vehicle_catalog] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور.']);
}
