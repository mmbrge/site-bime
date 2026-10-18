<?php
// فایل: api/visit_appt_actions.php
// پنل ← «بازدید سلامت و مدارک» ← نوبت‌های بازدیدِ خودرو (بدنه): تقویمِ کاری (هفته به هفته)، فهرستِ نوبت‌ها،
// نوبتِ دستی برای هر درخواست (هر زمانی، حتی خارج از برنامه)، لغو، و نوبتِ هر درخواست در صفحه‌ی درخواست.
// صدورِ گزارشِ بازدید از همان‌جا (وصل به درخواست) => بازدید تایید و نوبت «انجام شد» می‌شود (api/_visit_report_helpers.php).
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_health_admin.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$in = $in + $_GET;
$action = (string)($in['action'] ?? '');
$uid = intval($_SESSION['user_id']);

// ردیفِ نوبت + اطلاعاتِ درخواست برای نمایش
function va_rows($pdo, $where, array $args, $limit = 300) {
    $st = $pdo->prepare("SELECT ha.*, pc.unique_code, pc.plate, pc.status AS case_status, pc.insured_name, pc.person_id, p.full_name AS holder_name, p.mobile_number,
                                c.name AS company_name, u.full_name AS staff_name,
                                (SELECT hi.status FROM health_inspections hi WHERE hi.case_id = pc.id ORDER BY hi.id DESC LIMIT 1) AS insp_status
                           FROM health_appointments ha
                           JOIN policy_cases pc ON pc.id = ha.case_id
                           JOIN persons p ON p.id = pc.person_id
                           LEFT JOIN companies c ON c.id = p.company_id
                           LEFT JOIN users u ON u.id = ha.staff_id
                          WHERE $where ORDER BY ha.slot_at LIMIT " . intval($limit));
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $ts = strtotime($r['slot_at']);
        [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
        $rows[] = ['case_id' => (int)$r['case_id'], 'code' => $r['unique_code'], 'plate' => $r['plate'], 'insured' => $r['insured_name'] ?: $r['holder_name'],
                   'holder' => $r['holder_name'], 'mobile' => $r['mobile_number'], 'company' => $r['company_name'], 'case_status' => $r['case_status'],
                   'case_status_fa' => function_exists('case_status_fa') ? case_status_fa($r['case_status']) : $r['case_status'],
                   'slot_at' => $r['slot_at'], 'date' => date('Y-m-d', $ts), 'jdate' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd), 'time' => date('H:i', $ts),
                   'weekday' => HADM_WEEKDAYS[hadm_weekday($ts)], 'status' => $r['status'] ?: 'BOOKED', 'source' => $r['source'] ?: 'AUTO',
                   'staff' => $r['staff_name'], 'note' => $r['note'], 'vr_id' => $r['vr_id'] ? (int)$r['vr_id'] : null,
                   'insp_status' => $r['insp_status'], 'past' => $ts < time()];
    }
    return $rows;
}

try {
    hadm_ensure($pdo);
    $s = hadm_settings($pdo);
    $meta = ['self_enabled' => $s['hadm_self_enabled'] !== '0', 'pick' => $s['hadm_pick'] !== '0', 'window' => intval($s['hadm_window']), 'place' => $s['hadm_place'],
             'days' => array_map('intval', array_filter(explode(',', $s['hadm_days']), 'strlen')), 'start' => $s['hadm_start'], 'end' => $s['hadm_end'], 'slot' => intval($s['hadm_slot']), 'cap' => intval($s['hadm_cap'])];

    // تقویمِ کاری: $from روز از امروز (منفی = گذشته)، $days روز؛ نوبت‌های هر ساعت با درخواست‌هایش
    if ($action === 'calendar') {
        $from = max(-60, min(120, intval($in['from'] ?? 0)));
        $days = max(1, min(14, intval($in['days'] ?? 7)));
        $cal = hadm_slots($pdo, 0, $from, $days, true);
        $t0 = date('Y-m-d 00:00:00', strtotime('today') + $from * 86400);
        $t1 = date('Y-m-d 00:00:00', strtotime('today') + ($from + $days) * 86400);
        $appts = va_rows($pdo, "ha.slot_at >= ? AND ha.slot_at < ? AND ha.status <> 'CANCELED'", [$t0, $t1], 1000);
        $by = [];
        foreach ($appts as $a) $by[$a['date']][] = $a;
        foreach ($cal['days'] as &$d) {
            $d['appts'] = $by[$d['date']] ?? [];
            // نوبتِ دستیِ خارج از برنامه هم در روزِ خودش دیده شود
            $known = array_column($d['slots'], 'time');
            foreach ($d['appts'] as $a) if (!in_array($a['time'], $known, true)) { $d['slots'][] = ['at' => $a['slot_at'], 'time' => $a['time'], 'used' => 0, 'cap' => 0, 'free' => false, 'extra' => true]; $known[] = $a['time']; }
            usort($d['slots'], fn($x, $y) => strcmp($x['time'], $y['time']));
        }
        unset($d);
        $out(['ok' => true, 'meta' => $meta, 'from' => $from, 'days' => $cal['days']]);
    }

    // فهرستِ نوبت‌ها: upcoming (از امروز) | today | past (گذشته و انجام‌نشده) | done | canceled | all
    if ($action === 'list') {
        $scope = (string)($in['scope'] ?? 'upcoming');
        $w = ['1=1']; $a = [];
        if ($scope === 'upcoming') { $w[] = "ha.status = 'BOOKED' AND ha.slot_at >= ?"; $a[] = date('Y-m-d 00:00:00'); }
        elseif ($scope === 'today') { $w[] = "ha.status <> 'CANCELED' AND DATE(ha.slot_at) = ?"; $a[] = date('Y-m-d'); }
        elseif ($scope === 'past') { $w[] = "ha.status = 'BOOKED' AND ha.slot_at < ?"; $a[] = date('Y-m-d 00:00:00'); }
        elseif ($scope === 'done') $w[] = "ha.status = 'DONE'";
        elseif ($scope === 'canceled') $w[] = "ha.status = 'CANCELED'";
        $q = trim(p2e_digits((string)($in['q'] ?? '')));
        if ($q !== '') { $w[] = '(pc.plate LIKE ? OR pc.unique_code LIKE ? OR pc.insured_name LIKE ? OR p.full_name LIKE ? OR p.national_code LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l, $l, $l); }
        $rows = va_rows($pdo, implode(' AND ', $w), $a, 400);
        if (in_array($scope, ['done', 'canceled', 'all'], true)) $rows = array_reverse($rows);
        $cnt = $pdo->query("SELECT SUM(status = 'BOOKED' AND slot_at >= CURDATE()) up, SUM(status <> 'CANCELED' AND DATE(slot_at) = CURDATE()) td,
                                   SUM(status = 'BOOKED' AND slot_at < CURDATE()) pa, SUM(status = 'DONE') dn FROM health_appointments")->fetch(PDO::FETCH_ASSOC);
        $out(['ok' => true, 'meta' => $meta, 'rows' => $rows, 'counts' => array_map('intval', $cnt ?: [])]);
    }

    // نوبتِ یک درخواست (برای صفحه‌ی درخواست) + نوبت‌های آزادِ ۱۴ روزِ آینده
    if ($action === 'case') {
        $cid = intval($in['case_id'] ?? 0);
        $a = hadm_appt_get($pdo, $cid);
        $rows = $a ? va_rows($pdo, 'ha.case_id = ?', [$cid], 1) : [];
        $out(['ok' => true, 'meta' => $meta, 'appointment' => $rows[0] ?? null, 'slot_fa' => $a && $a['status'] !== 'CANCELED' ? hadm_appt_fa($pdo, $a) : '',
              'slots' => hadm_slots($pdo, $cid, 0, 14, true)['days']]);
    }

    // جستجوی درخواستِ بدنه‌ی باز برای نوبتِ دستی
    if ($action === 'search') {
        $q = trim(p2e_digits((string)($in['q'] ?? '')));
        if (mb_strlen($q) < 2) $out(['ok' => true, 'rows' => []]);
        $l = '%' . $q . '%';
        $st = $pdo->prepare("SELECT pc.id, pc.unique_code, pc.plate, pc.insured_name, p.full_name, p.national_code, c.name AS company_name, ha.slot_at, ha.status AS appt_status
                               FROM policy_cases pc JOIN persons p ON p.id = pc.person_id LEFT JOIN companies c ON c.id = p.company_id
                               LEFT JOIN health_appointments ha ON ha.case_id = pc.id
                              WHERE pc.insurance_type = 'BODY' AND pc.status NOT IN ('ISSUED', 'WITHDRAWN', 'CANCELLED', 'REGISTERED')
                                AND (pc.plate LIKE ? OR pc.unique_code LIKE ? OR pc.insured_name LIKE ? OR p.full_name LIKE ? OR p.national_code LIKE ?)
                              ORDER BY pc.id DESC LIMIT 20");
        $st->execute([$l, $l, $l, $l, $l]);
        $out(['ok' => true, 'rows' => array_map(fn($r) => ['case_id' => (int)$r['id'], 'code' => $r['unique_code'], 'plate' => $r['plate'], 'insured' => $r['insured_name'] ?: $r['full_name'],
                                                         'holder' => $r['full_name'], 'nid' => $r['national_code'], 'company' => $r['company_name'],
                                                         'has_appt' => $r['slot_at'] && $r['appt_status'] === 'BOOKED'], $st->fetchAll())]);
    }

    // نوبتِ دستی: با «at» (ساعتِ یکی از نوبت‌ها) یا تاریخِ شمسی + ساعتِ دلخواه
    if ($action === 'book') {
        $cid = intval($in['case_id'] ?? 0);
        $at = (string)($in['at'] ?? '');
        if ($at === '') {
            $d = p2e_digits(trim((string)($in['date'] ?? ''))); $tm = p2e_digits(trim((string)($in['time'] ?? '')));
            if (!preg_match('/^(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})$/', $d, $m) || !preg_match('/^(\d{1,2}):(\d{2})$/', $tm, $hm)) $out(['ok' => false, 'error' => 'تاریخ و ساعت را درست وارد کنید (۱۴۰۵/۰۷/۲۰ و ۱۰:۳۰).']);
            $at = date('Y-m-d H:i:s', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])) + intval($hm[1]) * 3600 + intval($hm[2]) * 60);
        }
        $r = hadm_book($pdo, $cid, $at, 'STAFF', $uid, (string)($in['note'] ?? ''));
        $out($r['ok'] ? ['ok' => true, 'slot_fa' => $r['slot_fa']] : $r);
    }
    if ($action === 'cancel') $out(hadm_cancel($pdo, intval($in['case_id'] ?? 0)));

    $out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[visit_appt_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
