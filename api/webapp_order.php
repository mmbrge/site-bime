<?php
// فایل: api/webapp_order.php
// بک‌اندِ مینی‌اپ «ثبت درخواست بیمه» - کل فرآیند ثالث/بدنه از انتخاب نوع تا تایید نهایی
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');

// از همان نشست «اپ اصلی» استفاده می‌کنیم (شخص‌محور، نه پرونده‌محور)؛ چون کاربر
// از داخل صفحه‌ی اصلیِ اپ وارد این بخش می‌شود و هنوز پرونده‌ای انتخاب نکرده است
function resolve_order_session($pdo, $token) {
    $stmt = $pdo->prepare("SELECT * FROM webapp_sessions WHERE token = ? AND mode = 'main' AND expires_at > NOW()");
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    if (!$session) return null;
    $stmt = $pdo->prepare("SELECT * FROM persons WHERE id = ?");
    $stmt->execute([$session['person_id']]);
    return $stmt->fetch() ?: null;
}

function get_owned_case($pdo, $personId, $caseId) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ?");
    $stmt->execute([$caseId, $personId]);
    return $stmt->fetch();
}

// تاریخ تولد را با اغماض کامل نسبت به ارقام فارسی/عربی و جداکننده‌های مختلف (/ - . فاصله)
// می‌پذیرد و به فرمت استاندارد یکنواخت YYYY/MM/DD برمی‌گرداند؛ اگر نامعتبر بود null می‌دهد
function normalize_jalali_date($str) {
    $str = trim((string)$str);
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $str = str_replace($arabic, $english, str_replace($persian, $english, $str));
    if (preg_match('/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/', $str, $m)) {
        $y = intval($m[1]); $mo = intval($m[2]); $d = intval($m[3]);
        if ($y >= 1300 && $y <= 1500 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
            return sprintf('%04d/%02d/%02d', $y, $mo, $d);
        }
    }
    return null;
}

try {
    // =====================================================================
    if ($action === 'init') {
        $person = resolve_order_session($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر یا منقضی است. لطفاً دوباره از ربات وارد شوید.']); exit; }

        $stmt = $pdo->prepare("SELECT id, max_quota, used_quota FROM introductions WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $intro = $stmt->fetch();
        $quotaOk = $intro && intval($intro['used_quota']) < intval($intro['max_quota'] ?: 4);

        $stmt = $pdo->prepare("SELECT id, insurance_type, updated_at FROM policy_cases WHERE person_id = ? AND status = 'REGISTERED' ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        $incomplete = $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'person_name' => $person['full_name'],
            'quota_ok' => $quotaOk,
            'quota_summary' => $intro ? (($intro['used_quota'] ?? 0) . ' / ' . ($intro['max_quota'] ?? 4)) : null,
            'incomplete_cases' => $incomplete,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'start_case') {
        $person = resolve_order_session($pdo, $jsonBody['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $type = ($jsonBody['insurance_type'] ?? '') === 'BODY' ? 'BODY' : 'THIRDPARTY';

        $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE person_id = ? AND insurance_type = ? AND status = 'REGISTERED' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id'], $type]);
        $existingId = $stmt->fetchColumn();

        if ($existingId && empty($jsonBody['discard_existing'])) {
            echo json_encode(['ok' => true, 'existing_case_id' => $existingId]);
            exit;
        }
        if ($existingId && !empty($jsonBody['discard_existing'])) {
            $pdo->prepare("DELETE FROM case_documents WHERE case_id = ?")->execute([$existingId]);
            $pdo->prepare("DELETE FROM policy_cases WHERE id = ?")->execute([$existingId]);
        }

        $stmt = $pdo->prepare("SELECT id, max_quota, used_quota FROM introductions WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $intro = $stmt->fetch();
        if (!$intro || intval($intro['used_quota']) >= intval($intro['max_quota'] ?: 4)) {
            echo json_encode(['ok' => false, 'error' => 'سقف صدور بیمه‌نامه با معرفی‌نامه فعلی شما تکمیل شده است.']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO policy_cases (introduction_id, person_id, insurance_type, unique_code) VALUES (?, ?, ?, '')");
        $stmt->execute([$intro['id'], $person['id'], $type]);
        $caseId = $pdo->lastInsertId();
        $pdo->prepare("UPDATE policy_cases SET unique_code = ? WHERE id = ?")->execute([generate_case_unique_code($caseId), $caseId]);
        $pdo->prepare("UPDATE introductions SET used_quota = used_quota + 1 WHERE id = ?")->execute([$intro['id']]);

        echo json_encode(['ok' => true, 'case_id' => $caseId]);
        exit;
    }

    // =====================================================================
    if ($action === 'get_case') {
        $person = resolve_order_session($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $case = get_owned_case($pdo, $person['id'], intval($_GET['case_id'] ?? 0));
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        echo json_encode(['ok' => true, 'case' => $case, 'person_name' => $person['full_name'],
            'addressee' => relationship_addressee($case['insured_relationship'])], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'get_saved_profile') {
        $person = resolve_order_session($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $case = get_owned_case($pdo, $person['id'], intval($_GET['case_id'] ?? 0));
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        $allSaved = json_decode($person['saved_profile'] ?: '{}', true) ?: [];
        $saved = $allSaved[get_profile_key($case)] ?? null;
        echo json_encode(['ok' => true, 'profile' => $saved], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    //  ذخیره‌ی هر مرحله - همه‌ی اعتبارسنجی‌ها اینجا و سمت سرور انجام می‌شود
    // =====================================================================
    if ($action === 'save_step') {
        $person = resolve_order_session($pdo, $jsonBody['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $caseId = intval($jsonBody['case_id'] ?? 0);
        $case = get_owned_case($pdo, $person['id'], $caseId);
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        $step = $jsonBody['step'] ?? '';
        $err = null;

        if ($step === 'relationship') {
            $rel = trim($jsonBody['relationship'] ?? '');
            if (!in_array($rel, relationship_options())) { $err = 'نسبت انتخاب‌شده نامعتبر است.'; }
            else {
                if ($rel === 'خودم') {
                    $pdo->prepare("UPDATE policy_cases SET insured_relationship=?, insured_name=?, insured_national_id=? WHERE id=?")
                        ->execute([$rel, $person['full_name'], $person['national_code'], $caseId]);
                } else {
                    $pdo->prepare("UPDATE policy_cases SET insured_relationship=?, insured_name=NULL, insured_national_id=NULL WHERE id=?")
                        ->execute([$rel, $caseId]);
                }
            }
        } elseif ($step === 'relative_info') {
            $name = trim($jsonBody['name'] ?? '');
            $nid = preg_replace('/\D/', '', $jsonBody['national_id'] ?? '');
            if (mb_strlen($name) < 3) $err = 'نام و نام‌خانوادگی را کامل وارد کنید.';
            elseif (strlen($nid) < 8 || strlen($nid) > 10) $err = 'کد ملی باید ۱۰ رقم باشد.';
            else {
                $nid = str_pad($nid, 10, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE policy_cases SET insured_name=?, insured_national_id=? WHERE id=?")->execute([$name, $nid, $caseId]);
            }
        } elseif ($step === 'personal_info') {
            $bd = normalize_jalali_date($jsonBody['birth_date'] ?? '');
            $addr = trim($jsonBody['address'] ?? '');
            $phone = trim($jsonBody['phone'] ?? '');
            $postal = preg_replace('/\D/', '', $jsonBody['postal_code'] ?? '');
            if (!$bd) $err = 'تاریخ تولد را درست وارد کنید (سال ۴ رقمی، مثل 1405/02/03).';
            elseif (mb_strlen($addr) < 10) $err = 'آدرس را کامل‌تر وارد کنید.';
            elseif (!preg_match('/^09\d{9}$/', $phone)) $err = 'شماره موبایل باید ۱۱ رقم و با 09 شروع شود.';
            elseif (strlen($postal) !== 10) $err = 'کد پستی باید دقیقاً ۱۰ رقم باشد.';
            else {
                $pdo->prepare("UPDATE policy_cases SET insured_birth_date=?, insured_address=?, insured_phone=?, insured_postal_code=? WHERE id=?")
                    ->execute([$bd, $addr, $phone, $postal, $caseId]);
                $allSaved = json_decode($person['saved_profile'] ?: '{}', true) ?: [];
                $allSaved[get_profile_key($case)] = ['birth_date' => $bd, 'address' => $addr, 'phone' => $phone, 'postal_code' => $postal];
                $pdo->prepare("UPDATE persons SET saved_profile=? WHERE id=?")->execute([json_encode($allSaved, JSON_UNESCAPED_UNICODE), $person['id']]);
            }
        } elseif ($step === 'plate') {
            $plate = trim($jsonBody['plate'] ?? '');
            if (!preg_match('/^\d{2}ایران - \d{3} \S+ \d{2}$/u', $plate)) { $err = 'پلاک نامعتبر است.'; }
            else {
                $pdo->prepare("UPDATE policy_cases SET plate=? WHERE id=?")->execute([$plate, $caseId]);
                $stmt = $pdo->prepare("SELECT id FROM person_vehicles WHERE person_id=? AND plate=?");
                $stmt->execute([$person['id'], $plate]);
                if (!$stmt->fetchColumn()) $pdo->prepare("INSERT INTO person_vehicles (person_id,plate) VALUES (?,?)")->execute([$person['id'], $plate]);
            }
        } elseif ($step === 'ownership') {
            $choice = ($jsonBody['ownership_choice'] ?? '') === 'سند' ? 'سند' : 'کارت ماشین';
            $pdo->prepare("UPDATE policy_cases SET ownership_choice=? WHERE id=?")->execute([$choice, $caseId]);
        } elseif ($step === 'liability') {
            $amount = intval($jsonBody['liability_limit'] ?? 0);
            if (!in_array($amount, liability_limit_options())) { $err = 'مبلغ انتخاب‌شده نامعتبر است.'; }
            else $pdo->prepare("UPDATE policy_cases SET liability_limit=? WHERE id=?")->execute([$amount, $caseId]);
        } elseif ($step === 'prev_body') {
            $has = ($jsonBody['prev_body_insurance'] ?? '') === 'بله' ? 'بله' : 'خیر';
            $pdo->prepare("UPDATE policy_cases SET prev_body_insurance=? WHERE id=?")->execute([$has, $caseId]);
            if ($has === 'خیر') $pdo->prepare("UPDATE policy_cases SET prev_body_claim=NULL, no_claim_years=NULL WHERE id=?")->execute([$caseId]);
        } elseif ($step === 'prev_body_claim') {
            $claim = ($jsonBody['prev_body_claim'] ?? '') === 'بله' ? 'بله' : 'خیر';
            $pdo->prepare("UPDATE policy_cases SET prev_body_claim=? WHERE id=?")->execute([$claim, $caseId]);
            if ($claim === 'بله') $pdo->prepare("UPDATE policy_cases SET no_claim_years=NULL WHERE id=?")->execute([$caseId]);
        } elseif ($step === 'no_claim_years') {
            $years = intval($jsonBody['no_claim_years'] ?? -1);
            if ($years < 0 || $years > 50) { $err = 'عدد سال نامعتبر است.'; }
            else $pdo->prepare("UPDATE policy_cases SET no_claim_years=? WHERE id=?")->execute([$years, $caseId]);
        } elseif ($step === 'coverages') {
            $cov = $jsonBody['coverages'] ?? [];
            $pdo->prepare("UPDATE policy_cases SET selected_coverages=? WHERE id=?")->execute([json_encode($cov, JSON_UNESCAPED_UNICODE), $caseId]);
        } elseif ($step === 'car_value') {
            if (!empty($jsonBody['auto_calc'])) {
                // ۰ به‌معنای «محاسبه‌ی خودکار توسط کارشناس» است (نه اینکه هنوز پاسخ نداده)
                $pdo->prepare("UPDATE policy_cases SET estimated_car_value=0 WHERE id=?")->execute([$caseId]);
            } else {
                $val = intval($jsonBody['car_value'] ?? 0);
                if ($val <= 0) { $err = 'مبلغ وارد‌شده نامعتبر است.'; }
                else $pdo->prepare("UPDATE policy_cases SET estimated_car_value=? WHERE id=?")->execute([$val, $caseId]);
            }
        } elseif ($step === 'edit_field') {
            // ویرایش تکی هر فیلد از صفحه‌ی خلاصه/تایید نهایی
            $allowed = ['plate', 'insured_name', 'insured_birth_date', 'insured_address', 'insured_phone', 'insured_postal_code'];
            $field = $jsonBody['field'] ?? '';
            $value = trim($jsonBody['value'] ?? '');
            if (!in_array($field, $allowed, true)) { $err = 'فیلد نامعتبر است.'; }
            else {
                if ($field === 'insured_phone' && !preg_match('/^09\d{9}$/', $value)) $err = 'شماره موبایل نامعتبر است.';
                elseif ($field === 'insured_postal_code' && !preg_match('/^\d{10}$/', $value)) $err = 'کد پستی باید ۱۰ رقم باشد.';
                elseif ($field === 'insured_birth_date') {
                    $normalized = normalize_jalali_date($value);
                    if (!$normalized) $err = 'فرمت تاریخ نامعتبر است.'; else $value = $normalized;
                }
                elseif ($field === 'plate' && !preg_match('/^\d{2}ایران - \d{3} \S+ \d{2}$/u', $value)) $err = 'پلاک نامعتبر است.';
                if (!$err) $pdo->prepare("UPDATE policy_cases SET {$field} = ? WHERE id = ?")->execute([$value, $caseId]);
            }
        } else {
            $err = 'مرحله نامعتبر است.';
        }

        if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
        echo json_encode(['ok' => true]);
        exit;
    }

    // =====================================================================
    //  مدارک - فهرست پویا بر اساس انتخاب‌های کاربر (دقیقاً منطق مشترک با ربات)
    // =====================================================================
    if ($action === 'required_docs') {
        $person = resolve_order_session($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $case = get_owned_case($pdo, $person['id'], intval($_GET['case_id'] ?? 0));
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
        $stmt = $pdo->prepare("SELECT doc_key, status, reject_reason FROM case_documents WHERE case_id = ? ORDER BY id DESC");
        $stmt->execute([$case['id']]);
        $docsRows = $stmt->fetchAll();
        $satisfiedMap = [];
        foreach ($docsRows as $d) {
            if (!isset($satisfiedMap[$d['doc_key']])) $satisfiedMap[$d['doc_key']] = $d; // آخرین وضعیت هر مدرک
        }

        $list = [];
        foreach ($required as $key => $label) {
            $status = $satisfiedMap[$key]['status'] ?? null;
            $list[] = ['key' => $key, 'label' => $label, 'status' => $status, 'reject_reason' => $satisfiedMap[$key]['reject_reason'] ?? null];
        }
        $allDone = !in_array(null, array_column($list, 'status')) && !in_array('REJECTED', array_column($list, 'status'));
        echo json_encode(['ok' => true, 'docs' => $list, 'all_done' => $allDone], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload_doc') {
        try {
            $person = resolve_order_session($pdo, $_POST['token'] ?? '');
            if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
            $caseId = intval($_POST['case_id'] ?? 0);
            $case = get_owned_case($pdo, $person['id'], $caseId);
            if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
            $docKey = $_POST['doc_key'] ?? '';
            $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
            if (!isset($required[$docKey])) { echo json_encode(['ok' => false, 'error' => 'مورد انتخابی نامعتبر است.']); exit; }
            if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
                echo json_encode(['ok' => false, 'error' => 'فایلی دریافت نشد. لطفاً دوباره تلاش کنید.']); exit;
            }

            $siteRoot = dirname(__DIR__);
            $tmpDir = build_temp_plate_path($siteRoot, time(), $case['insured_national_id'] ?: $person['national_code'], $case['plate']);
            if (!is_dir($tmpDir)) {
                if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
                    echo json_encode(['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی ذخیره‌سازی روی سرور.']); exit;
                }
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
            $destPath = unique_dest_path($tmpDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور. لطفاً دوباره تلاش کنید.']); exit;
            }
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) compress_image_if_needed($destPath);
            $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');

            // اگر مدرک قبلاً رد شده بود، نسخه‌ی جدید جای آن را می‌گیرد
            $pdo->prepare("DELETE FROM case_documents WHERE case_id = ? AND doc_key = ? AND status IN ('PENDING','REJECTED')")->execute([$caseId, $docKey]);
            $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'PENDING')")
                ->execute([$caseId, $docKey, $required[$docKey], $relPath]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'خطای سرور در بارگذاری مدرک: ' . $e->getMessage()]);
        }
        exit;
    }

    // =====================================================================
    //  خلاصه و تایید نهایی
    // =====================================================================
    if ($action === 'finalize') {
        $person = resolve_order_session($pdo, $jsonBody['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $caseId = intval($jsonBody['case_id'] ?? 0);
        $case = get_owned_case($pdo, $person['id'], $caseId);
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        // مدارک و بازدید سلامت دیگر اینجا گرفته نمی‌شوند؛ کاربر برایشان به «هاب بازدید»
        // (که در مرورگر داخلی بله باز می‌شود و دوربین واقعی دارد) هدایت می‌شود
        $pdo->prepare("UPDATE policy_cases SET status = 'AWAITING_DOCS' WHERE id = ?")->execute([$caseId]);
        notify_customer_app($pdo, $person['id'], $person['bale_chat_id'], 'درخواست ثبت شد',
            "📝 اطلاعات اولیه‌ی درخواست بیمه {$case['unique_code']} ثبت شد.\nبرای بارگذاری مدارک و ثبت نهایی، لطفاً به بخش «بازدید مدارک و سلامت خودرو» مراجعه کنید.",
            'success', $caseId);
        notify_admin($pdo, "📝 درخواست بیمه جدید (از اپ) - اطلاعات اولیه ثبت شد.\nشناسه: {$case['unique_code']}\nبیمه‌گذار: {$case['insured_name']}\nنوع: " . insurance_type_fa($case['insurance_type']) . "\nپلاک: " . ($case['plate'] ?: '-'));

        echo json_encode(['ok' => true, 'unique_code' => $case['unique_code']]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
