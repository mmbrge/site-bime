<?php
// فایل: api/webapp_health.php
// بک‌اندِ مینی‌اپ بازدید سلامت خودرو (بدون نشست ادمین؛ ورود فقط با توکن یک‌بار-صادرشده)
// هشدار/نوتیس‌های احتمالی PHP هرگز نباید مستقیم چاپ شوند، وگرنه خروجی JSON خراب می‌شود
// و سمت کلاینت با خطای «parse» مواجه می‌شود که اشتباهاً به‌نظر «خطا در اتصال» می‌آید
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$siteRoot = dirname(__DIR__);

function resolve_session($pdo, $token) {
    $stmt = $pdo->prepare("SELECT * FROM webapp_sessions WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

function get_active_attempt($pdo, $session) {
    if ($session['mode'] === 'case') {
        $stmt = $pdo->prepare("SELECT * FROM health_attempts WHERE case_id = ? AND status = 'IN_PROGRESS' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$session['case_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM health_attempts WHERE person_id = ? AND free_plate = ? AND status = 'IN_PROGRESS' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$session['person_id'], $session['free_plate']]);
    }
    return $stmt->fetch();
}

function attempt_daily_key($session) {
    return $session['mode'] === 'case' ? ['case_id', $session['case_id']] : ['person_id_free', $session['person_id'] . '|' . $session['free_plate']];
}

function get_case_plate($pdo, $caseId) {
    $stmt = $pdo->prepare("SELECT plate FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    return $stmt->fetchColumn();
}

function resolve_plate($pdo, $session) {
    return $session['mode'] === 'case' ? get_case_plate($pdo, $session['case_id']) : $session['free_plate'];
}

try {
    // =====================================================================
    //  لیست بازدیدهای قبلی همین پلاک/پرونده (برای صفحه‌ی «بازدیدهای انجام‌شده»)
    // =====================================================================
    // =====================================================================
    //  وضعیت زنده‌ی یک تلاش (برای وقتی کاربر از صفحه‌ی مرورگرِ دوربین به مینی‌اپ برمی‌گردد،
    //  تا مینی‌اپ خودش را با آخرین وضعیت هماهنگ کند، بدون نیاز به بارگذاری مجدد کامل)
    // =====================================================================
    if ($action === 'attempt_state') {
        $token = $_GET['token'] ?? '';
        $session = resolve_session($pdo, $token);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $attemptId = intval($_GET['attempt_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM health_attempts WHERE id = ?");
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();
        if (!$attempt) { echo json_encode(['ok' => false, 'error' => 'یافت نشد.']); exit; }
        echo json_encode(['ok' => true, 'status' => $attempt['status'], 'state' => json_decode($attempt['state'] ?: '{}', true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'my_inspections') {
        $token = $_GET['token'] ?? '';
        $session = resolve_session($pdo, $token);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        if ($session['mode'] === 'case') {
            $stmt = $pdo->prepare("SELECT * FROM health_inspections WHERE case_id = ? ORDER BY created_at DESC");
            $stmt->execute([$session['case_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM health_inspections WHERE person_id = ? AND free_plate = ? ORDER BY created_at DESC");
            $stmt->execute([$session['person_id'], $session['free_plate']]);
        }
        $rows = $stmt->fetchAll();
        $result = array_map(function ($r) {
            return [
                'id' => $r['id'], 'inspection_number' => $r['inspection_number'], 'status' => $r['status'],
                'reject_reason' => $r['reject_reason'], 'created_at' => $r['created_at'],
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'init') {
        $token = $_GET['token'] ?? '';
        $session = resolve_session($pdo, $token);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'لینک منقضی شده یا نامعتبر است. لطفاً دوباره از ربات وارد شوید.']); exit; }

        $stmt = $pdo->prepare("SELECT full_name, national_code FROM persons WHERE id = ?");
        $stmt->execute([$session['person_id']]);
        $person = $stmt->fetch();

        $plate = resolve_plate($pdo, $session);

        // محدودیت روزانه دقیقاً بر اساس خودِ پلاک است (نه شخص، نه حتی پرونده) - چون یک پلاک
        // ممکن است زیر چند پرونده (ثالث/بدنه) ثبت شده باشد
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_attempts WHERE plate = ? AND attempt_date = ? AND status != 'EXPIRED'");
        $stmt->execute([$plate, $today]);
        $attemptsToday = intval($stmt->fetchColumn());

        $active = get_active_attempt($pdo, $session);
        $state = $active ? json_decode($active['state'] ?: '{}', true) : null;

        $steps = [];
        foreach (health_inspection_steps() as $key => $s) {
            $steps[] = ['key' => $key, 'label' => $s['label'], 'required' => $s['required'], 'multi' => $s['multi']];
        }

        echo json_encode([
            'ok' => true,
            'person_name' => $person['full_name'],
            'plate' => $plate,
            'mode' => $session['mode'],
            'steps' => $steps,
            'step_seconds' => HEALTH_STEP_SECONDS,
            'total_seconds' => health_total_seconds(),
            'max_attempts_per_day' => HEALTH_MAX_ATTEMPTS_PER_DAY,
            'attempts_today' => $attemptsToday,
            'active_attempt' => $active ? [
                'id' => $active['id'],
                'started_at' => strtotime($active['started_at']),
                'state' => $state,
            ] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'start_attempt') {
        $token = $_POST['token'] ?? '';
        $session = resolve_session($pdo, $token);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $plate = resolve_plate($pdo, $session);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_attempts WHERE plate = ? AND attempt_date = ? AND status != 'EXPIRED'");
        $stmt->execute([$plate, $today]);
        if (intval($stmt->fetchColumn()) >= HEALTH_MAX_ATTEMPTS_PER_DAY) {
            echo json_encode(['ok' => false, 'error' => 'امروز ۲ بار برای این پلاک تلاش کرده‌اید. لطفاً فردا دوباره امتحان کنید.']); exit;
        }

        $steps = array_keys(health_inspection_steps());
        $state = ['current_step' => $steps[0], 'step_started_at' => null, 'photos' => []];

        $stmt = $pdo->prepare("INSERT INTO health_attempts (case_id, person_id, free_plate, plate, attempt_date, status, state) VALUES (?, ?, ?, ?, ?, 'IN_PROGRESS', ?)");
        $stmt->execute([
            $session['mode'] === 'case' ? $session['case_id'] : null,
            $session['person_id'],
            $session['mode'] === 'free' ? $session['free_plate'] : null,
            $plate,
            $today,
            json_encode($state, JSON_UNESCAPED_UNICODE),
        ]);
        echo json_encode(['ok' => true, 'attempt_id' => $pdo->lastInsertId(), 'state' => $state]);
        exit;
    }

    // =====================================================================
    if ($action === 'guide_image') {
        $step = preg_replace('/[^0-9]/', '', $_GET['step'] ?? '');
        $path = find_health_guide_image($step);
        if (!$path) { http_response_code(404); exit; }
        header('Content-Type: image/' . (pathinfo($path, PATHINFO_EXTENSION) === 'png' ? 'png' : 'jpeg'));
        readfile($path);
        exit;
    }

    // بقیه‌ی اکشن‌ها به یک attempt فعال نیاز دارند
    $token = $_POST['token'] ?? '';
    $attemptId = intval($_POST['attempt_id'] ?? 0);
    $session = resolve_session($pdo, $token);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM health_attempts WHERE id = ? AND status = 'IN_PROGRESS'");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt) { echo json_encode(['ok' => false, 'error' => 'این بازدید فعال نیست یا قبلاً باطل/تمام شده.']); exit; }

    $state = json_decode($attempt['state'] ?: '{}', true) ?: [];
    $steps = health_inspection_steps();
    $stepKeys = array_keys($steps);

    // بررسی انقضای زمان (کل و مرحله) - هر بار قبل از پردازش هر اکشن
    $now = time();
    $totalExpired = ($now - strtotime($attempt['started_at'])) > health_total_seconds();
    $stepExpired = !empty($state['step_started_at']) && ($now - $state['step_started_at']) > HEALTH_STEP_SECONDS;
    if (($totalExpired || $stepExpired) && $action !== 'cancel') {
        // نکته: وضعیت 'CANCELLED' ثبت می‌شود نه 'EXPIRED'، چون طبق تصمیم، اتمامِ زمان دقیقاً
        // مثل انصراف است و باید به‌عنوان یک تلاشِ مصرف‌شده‌ی امروز حساب شود (شمارش روزانه
        // فقط 'EXPIRED' را نادیده می‌گیرد)
        $pdo->prepare("UPDATE health_attempts SET status = 'CANCELLED' WHERE id = ?")->execute([$attemptId]);
        echo json_encode(['ok' => false, 'expired' => true, 'error' => $totalExpired ? 'زمان کل بازدید تمام شد.' : 'زمان این مرحله تمام شد.']);
        exit;
    }

    // =====================================================================
    if ($action === 'start_capture') {
        // تایمر ۱.۵ دقیقه‌ی هر مرحله از همین لحظه (که کاربر دکمه‌ی دوربین/انتخاب فایل را زده) شروع می‌شود،
        // نه از لحظه‌ای که خودِ مرحله (با عکس راهنما) روی صفحه ظاهر شد
        $state['step_started_at'] = time();
        $pdo->prepare("UPDATE health_attempts SET state = ? WHERE id = ?")->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $attemptId]);
        echo json_encode(['ok' => true, 'step_deadline' => time() + HEALTH_STEP_SECONDS]);
        exit;
    }

    // =====================================================================
    if ($action === 'upload_step') {
        // سرورْ مرجع اصلیِ «مرحله‌ی فعلی» است، نه چیزی که کلاینت می‌فرستد؛ این‌طوری اگر
        // به هر دلیلی (مثلاً بارگذاری مجدد WebView هنگام باز شدن گالری/دوربین) وضعیت
        // سمت کلاینت با سرور ناهماهنگ شود، کاربر همچنان با خطای «نامعتبر» متوقف نمی‌شود
        $stepKey = $state['current_step'] ?? null;
        if (!$stepKey || !isset($steps[$stepKey])) {
            echo json_encode(['ok' => false, 'error' => 'این بازدید به پایان رسیده یا نامعتبر است.']); exit;
        }
        if (empty($_FILES['photo'])) { echo json_encode(['ok' => false, 'error' => 'فایلی دریافت نشد.']); exit; }

        $plate = resolve_plate($pdo, $session);
        $stmt = $pdo->prepare("SELECT national_code FROM persons WHERE id = ?");
        $stmt->execute([$session['person_id']]);
        $nationalId = $stmt->fetchColumn();

        $tmpDir = build_temp_plate_path($siteRoot, time(), $nationalId, $plate);
        if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) $ext = 'jpg';

        // نام فایل بر اساس عنوانِ فارسیِ همان مرحله ذخیره می‌شود (نه timestamp)، تا در بایگانی
        // مشخص باشد هر عکس مربوط به کدام نمای خودرو است - مثلاً «۰۳ - نمای جلو راست ۴۵ درجه.jpg»
        $stepIndex = array_search($stepKey, array_keys($steps), true);
        $stepNum = str_pad((string)(($stepIndex === false ? 0 : $stepIndex) + 1), 2, '0', STR_PAD_LEFT);
        $stepLabel = sanitize_folder_name($steps[$stepKey]['label'] ?? $stepKey);
        // کد ملی به نام فایل اضافه می‌شود تا اگر چند شخص همزمان در پوشه‌ی موقت فایل داشتند، قاطی نشود
        // در مرحله‌ی چندعکسی (مواضع آسیب‌دیده) یک شماره‌ی ترتیبی هم به انتهای نام اضافه می‌شود
        $seqSuffix = '';
        if (!empty($steps[$stepKey]['multi'])) {
            $already = $state['photos'][$stepKey] ?? [];
            if (!is_array($already)) $already = $already ? [$already] : [];
            $seqSuffix = ' - ' . (count($already) + 1);
        }
        $baseName = "{$stepNum} - {$stepLabel}{$seqSuffix} - " . sanitize_folder_name($nationalId ?: 'نامشخص');
        $destPath = unique_dest_path($tmpDir . '/' . $baseName . '.' . $ext);
        move_uploaded_file($_FILES['photo']['tmp_name'], $destPath);
        compress_image_if_needed($destPath);
        $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');

        $step = $steps[$stepKey];
        if ($step['multi']) {
            $state['photos'][$stepKey][] = $relPath;
            $pdo->prepare("UPDATE health_attempts SET state = ? WHERE id = ?")->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $attemptId]);
            echo json_encode(['ok' => true, 'stay_on_step' => true, 'count' => count($state['photos'][$stepKey])]);
            exit;
        }

        $state['photos'][$stepKey] = $relPath;
        $idx = array_search($stepKey, $stepKeys);
        $next = $stepKeys[$idx + 1] ?? null;
        $state['current_step'] = $next;
        // مهم: تایمر مرحله‌ی جدید هنوز شروع نمی‌شود - فقط وقتی کاربر روی «ثبت بازدید این مرحله»
        // بزند (اکشن start_capture) واقعاً شروع می‌شود؛ تا آن لحظه مرحله منقضی حساب نمی‌شود
        $state['step_started_at'] = null;
        $pdo->prepare("UPDATE health_attempts SET state = ? WHERE id = ?")->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $attemptId]);

        echo json_encode(['ok' => true, 'next_step' => $next, 'step_deadline' => null]);
        exit;
    }

    // =====================================================================
    //  بازگشت به مرحله‌ی قبل (برای بازبینی/گرفتن دوباره‌ی عکس)
    // =====================================================================
    if ($action === 'go_back_step') {
        $stepKeys = array_keys($steps);
        $curKey = $state['current_step'] ?? null;
        // اگر همه‌ی مراحل تمام شده بود (در حال بازبینی نهایی)، از آخرین مرحله شروع به بازگشت می‌کنیم
        $curIdx = $curKey ? array_search($curKey, $stepKeys) : count($stepKeys);
        $prevIdx = $curIdx - 1;
        if ($prevIdx < 0) { echo json_encode(['ok' => false, 'error' => 'مرحله‌ی قبلی وجود ندارد.']); exit; }

        $prevKey = $stepKeys[$prevIdx];
        // عکسِ ثبت‌شده‌ی همان مرحله را پاک می‌کنیم تا بتواند دوباره ثبت شود
        if (isset($state['photos'][$prevKey])) {
            $old = $state['photos'][$prevKey];
            foreach (is_array($old) ? $old : [$old] as $p) {
                if ($p) @unlink($siteRoot . '/' . $p);
            }
            unset($state['photos'][$prevKey]);
        }
        $state['current_step'] = $prevKey;
        // مثل بقیه‌ی مراحل: تایمر تا لحظه‌ی زدن «ثبت بازدید این مرحله» شروع نمی‌شود
        $state['step_started_at'] = null;
        $pdo->prepare("UPDATE health_attempts SET state = ? WHERE id = ?")->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $attemptId]);

        echo json_encode(['ok' => true, 'step' => $prevKey, 'step_deadline' => null, 'multi_count' => 0]);
        exit;
    }

    // =====================================================================
    if ($action === 'finish_multi_step' || $action === 'skip_step') {
        $stepKey = $state['current_step'] ?? null;
        if (!$stepKey) { echo json_encode(['ok' => false, 'error' => 'مرحله‌ای فعال نیست.']); exit; }
        if (empty($state['photos'][$stepKey])) $state['photos'][$stepKey] = [];

        $idx = array_search($stepKey, $stepKeys);
        $next = $stepKeys[$idx + 1] ?? null;
        $state['current_step'] = $next;
        $state['step_started_at'] = null;
        $pdo->prepare("UPDATE health_attempts SET state = ? WHERE id = ?")->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $attemptId]);
        echo json_encode(['ok' => true, 'next_step' => $next, 'step_deadline' => null]);
        exit;
    }

    // =====================================================================
    if ($action === 'cancel') {
        $pdo->prepare("UPDATE health_attempts SET status = 'CANCELLED' WHERE id = ?")->execute([$attemptId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =====================================================================
    if ($action === 'finalize') {
        if (!empty($state['current_step'])) { echo json_encode(['ok' => false, 'error' => 'هنوز همه‌ی مراحل تکمیل نشده‌اند.']); exit; }

        $plate = resolve_plate($pdo, $session);

        if ($session['mode'] === 'case') {
            $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
            $stmt->execute([$session['case_id']]);
            $case = $stmt->fetch();
            $caseFolder = resolve_case_folder($pdo, $siteRoot, $case);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_inspections WHERE case_id = ?");
            $stmt->execute([$session['case_id']]);
        } else {
            // معرفی‌نامه‌ی خودِ شخص را می‌خواهیم (نه introduction_id یک پرونده - آن ستون روی این جدول وجود ندارد)
            $stmt2 = $pdo->prepare("SELECT id FROM introductions WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt2->execute([$session['person_id']]);
            $introId = $stmt2->fetchColumn();
            $caseFolder = build_free_inspection_folder($pdo, $siteRoot, $introId, $plate);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_inspections WHERE person_id = ? AND free_plate = ?");
            $stmt->execute([$session['person_id'], $plate]);
        }
        $num = intval($stmt->fetchColumn()) + 1;

        $pendingFolder = $caseFolder . '/بازدید سلامت (در انتظار تایید) ' . $num;
        if (!file_exists($pendingFolder)) mkdir($pendingFolder, 0777, true);

        $finalPhotos = [];
        $tempDirsTouched = [];
        foreach ($state['photos'] as $stepKey => $val) {
            $paths = is_array($val) ? $val : [$val];
            $stepLabel = $steps[$stepKey]['label'] ?? $stepKey;
            $subIndex = 1;
            foreach ($paths as $tempRelPath) {
                if (!$tempRelPath) continue;
                $absTemp = $siteRoot . '/' . $tempRelPath;
                $tempDirsTouched[] = dirname($absTemp);
                $ext = pathinfo($absTemp, PATHINFO_EXTENSION) ?: 'jpg';
                $isMulti = count($paths) > 1;
                $filename = health_step_filename($stepLabel, $plate, $ext, $isMulti ? $subIndex : null);
                $destAbs = unique_dest_path($pendingFolder . '/' . $filename);
                if (file_exists($absTemp)) @rename($absTemp, $destAbs);
                $finalPhotos[$isMulti ? "{$stepKey}-{$subIndex}" : $stepKey] = ltrim(str_replace($siteRoot, '', $destAbs), '/');
                $subIndex++;
            }
        }
        foreach (array_unique($tempDirsTouched) as $d) cleanup_empty_dirs_upward($d, temp_archive_root($siteRoot));

        $stmt = $pdo->prepare("INSERT INTO health_inspections (case_id, person_id, free_plate, inspection_number, photos, photos_folder_path, status) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')");
        $stmt->execute([
            $session['mode'] === 'case' ? $session['case_id'] : null,
            $session['mode'] === 'free' ? $session['person_id'] : null,
            $session['mode'] === 'free' ? $plate : null,
            $num,
            json_encode($finalPhotos, JSON_UNESCAPED_UNICODE),
            $pendingFolder,
        ]);
        $pdo->prepare("UPDATE health_attempts SET status = 'COMPLETED' WHERE id = ?")->execute([$attemptId]);

        if ($session['mode'] === 'case' && $session['case_id']) {
            maybe_advance_case_status($pdo, $session['case_id']);
        }

        echo json_encode(['ok' => true, 'inspection_number' => $num]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
