<?php
// فایل: api/finance_core.php
// موتور مالی: دوره‌بندی، تولید اقساط، شماره‌گذاری صورتحساب و محاسبات مانده
require_once __DIR__ . '/_case_helpers.php';

// =====================================================================
//  تنظیمات
// =====================================================================
function fin_settings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query("SELECT setting_key, setting_value FROM finance_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'period_cutoff_day' => '25',
        'default_installments' => '9',
        'installment_method' => 'mamut',
        'rule_sequential_payment' => '1',
        'rule_collect_before_pay' => '1',
        'invoice_prefix' => 'SM49357',
        'invoice_counter' => '0',
        'invoice_counter_year' => '',
    ], $rows ?: []);
    return $cache;
}

function fin_set($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO finance_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, $value, $value]);
}

// =====================================================================
//  کمکی‌های تاریخ شمسی
// =====================================================================
function fin_jalali_month_len($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    // اسفند: بستگی به کبیسه دارد
    $r = (($jy - ($jy > 0 ? 474 : 473)) % 2820) + 474;
    return ((($r + 38) * 682) % 2816 < 682) ? 30 : 29;
}

// ماهِ بعدی را برمی‌گرداند
function fin_next_month($jy, $jm, $step = 1) {
    $total = ($jy * 12) + ($jm - 1) + $step;
    return [intdiv($total, 12), ($total % 12) + 1];
}

function fin_month_name($jm) {
    $names = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    return $names[$jm - 1] ?? '';
}

// =====================================================================
//  دوره‌های مالی
//  قانون: اگر بیمه‌نامه در روزِ cutoff یا بعد از آن صادر شده باشد، به دوره‌ی ماه بعد می‌رود.
// =====================================================================
function fin_resolve_period_for_date($pdo, $jy, $jm, $jd) {
    $cutoff = intval(fin_settings($pdo)['period_cutoff_day']);
    if ($jd >= $cutoff) {
        [$jy, $jm] = fin_next_month($jy, $jm);
    }
    return fin_ensure_period($pdo, $jy, $jm);
}

// دوره را پیدا یا ایجاد می‌کند (و دوره‌های گذشته را خودکار می‌بندد)
function fin_ensure_period($pdo, $jy, $jm) {
    $stmt = $pdo->prepare("SELECT * FROM billing_periods WHERE jalali_year = ? AND jalali_month = ?");
    $stmt->execute([$jy, $jm]);
    $p = $stmt->fetch();
    if ($p) { fin_auto_close_periods($pdo); return $p; }

    $cutoff = intval(fin_settings($pdo)['period_cutoff_day']);
    // بازه‌ی دوره: از روزِ cutoff ماه قبل تا یک روز پیش از cutoff همین ماه
    [$py, $pm] = fin_next_month($jy, $jm, -1);
    $startTs = jalali_to_gregorian_ts($py, $pm, min($cutoff, fin_jalali_month_len($py, $pm)));
    $endTs   = jalali_to_gregorian_ts($jy, $jm, min($cutoff, fin_jalali_month_len($jy, $jm))) - 86400;

    $title = fin_month_name($jm) . ' ' . $jy;
    $pdo->prepare("INSERT INTO billing_periods (jalali_year, jalali_month, title, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$jy, $jm, $title, date('Y-m-d', $startTs), date('Y-m-d', $endTs)]);

    fin_auto_close_periods($pdo);
    $stmt->execute([$jy, $jm]);
    return $stmt->fetch();
}

// هر دوره‌ای که تاریخ پایانش گذشته، خودکار بسته می‌شود
function fin_auto_close_periods($pdo) {
    $pdo->prepare("UPDATE billing_periods SET status = 'CLOSED', closed_at = NOW()
                   WHERE status = 'OPEN' AND ends_at < CURDATE()")->execute();
}

// دوره‌ی جاری (بر اساس امروز)
function fin_current_period($pdo) {
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($now->getTimestamp());
    return fin_resolve_period_for_date($pdo, $jy, $jm, $jd);
}

// =====================================================================
//  تقسیم مبلغ به اقساط - دو روش
//  در هر دو روش، جمعِ اقساط دقیقاً برابرِ مبلغ کل است (حتی یک ریال اختلاف نباشد).
// =====================================================================
function fin_split_installments($total, $count, $method = 'mamut') {
    $total = (int)$total;
    $count = max(1, (int)$count);
    if ($total <= 0) return array_fill(0, $count, 0);

    if ($method === 'easy') {
        // تقسیم دقیق؛ باقیمانده به قسط اول
        $base = intdiv($total, $count);
        $parts = array_fill(0, $count, $base);
        $parts[0] += $total - ($base * $count);
        return $parts;
    }

    // روش ماموت: اقساط رُند به هزار، و اختلافِ نهایی روی قسط اول تنظیم می‌شود
    $base = intdiv(intdiv($total, $count), 1000) * 1000;
    $parts = array_fill(0, $count, $base);
    // قسط اول همه‌ی خرده‌ها را می‌گیرد، سپس رُند می‌شود
    $parts[0] = (int) (round(($base + ($total - $base * $count)) / 1000) * 1000);
    if ($count > 1) {
        $rest = $total - $parts[0];
        $other = (int) (round(($rest / ($count - 1)) / 1000) * 1000);
        for ($i = 1; $i < $count; $i++) $parts[$i] = $other;
    }
    // تضمین برابری دقیق: هر اختلافی باقی ماند، روی قسط اول اعمال می‌شود
    $diff = $total - array_sum($parts);
    $parts[0] += $diff;
    return $parts;
}

// =====================================================================
//  تولید اقساط یک بیمه‌نامه (بعد از صدور)
//  اقساط از ماهِ بعدِ دوره شروع می‌شوند و سررسید هرکدام پانزدهمِ آن ماه است.
// =====================================================================
function fin_generate_installments($pdo, $caseId, $count = null, $method = null) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case || $case['status'] !== 'ISSUED') return ['ok' => false, 'error' => 'پرونده صادر نشده است.'];
    if (empty($case['total_premium'])) return ['ok' => false, 'error' => 'حق بیمه‌ی این پرونده ثبت نشده است.'];

    $s = fin_settings($pdo);
    $count  = $count  ?: intval($s['default_installments']);
    $method = $method ?: $s['installment_method'];

    // دوره بر اساس تاریخ صدور تعیین می‌شود
    $issuedTs = $case['issued_at'] ? strtotime($case['issued_at']) : time();
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($issuedTs);
    $period = fin_resolve_period_for_date($pdo, $jy, $jm, $jd);

    $parts = fin_split_installments($case['total_premium'], $count, $method);

    $pdo->prepare("DELETE FROM policy_installments WHERE case_id = ?")->execute([$caseId]);
    $ins = $pdo->prepare("INSERT INTO policy_installments (case_id, period_id, inst_number, amount, due_jalali, due_date)
                          VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($parts as $i => $amount) {
        // قسط اول در ماهِ بعد از دوره سررسید می‌شود
        [$dy, $dm] = fin_next_month($period['jalali_year'], $period['jalali_month'], $i + 1);
        $dd = min(15, fin_jalali_month_len($dy, $dm));
        $dueJ = sprintf('%04d/%02d/%02d', $dy, $dm, $dd);
        $dueG = date('Y-m-d', jalali_to_gregorian_ts($dy, $dm, $dd));
        $ins->execute([$caseId, $period['id'], $i + 1, $amount, $dueJ, $dueG]);
    }

    $pdo->prepare("UPDATE policy_cases SET installments_generated = 1 WHERE id = ?")->execute([$caseId]);
    return ['ok' => true, 'count' => count($parts), 'period' => $period['title'], 'parts' => $parts];
}

// =====================================================================
//  محاسبه‌ی پرداختی و مانده‌ی هر قسط (همیشه محاسبه‌ای، نه ذخیره‌شده)
// =====================================================================
function fin_installment_paid($pdo, $installmentId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE installment_id = ?");
    $stmt->execute([$installmentId]);
    return intval($stmt->fetchColumn());
}

// اقساط یک پرونده همراه با پرداختی و مانده
function fin_case_installments($pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT pi.*, COALESCE(SUM(pa.amount), 0) AS paid
        FROM policy_installments pi
        LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
        WHERE pi.case_id = ?
        GROUP BY pi.id
        ORDER BY pi.inst_number ASC
    ");
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['paid'] = intval($r['paid']);
        $r['remaining'] = max(0, intval($r['amount']) - $r['paid']);
        $r['pay_status'] = $r['remaining'] <= 0 ? 'PAID' : ($r['paid'] > 0 ? 'PARTIAL' : 'UNPAID');
    }
    return $rows;
}

// =====================================================================
//  شماره‌گذاری صورتحساب: SM49357-05-00001
//  شمارنده اتمیک افزایش می‌یابد تا دو صورتحساب هم‌شماره نشوند.
// =====================================================================
function fin_next_invoice_no($pdo) {
    $s = fin_settings($pdo);
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    [$jy, , ] = jalali_from_gregorian_ts($now->getTimestamp());
    $yy = substr((string)$jy, -2);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM finance_settings WHERE setting_key IN ('invoice_counter','invoice_counter_year') FOR UPDATE");
        $stmt->execute();
        $rows = $pdo->query("SELECT setting_key, setting_value FROM finance_settings WHERE setting_key IN ('invoice_counter','invoice_counter_year')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $counter = intval($rows['invoice_counter'] ?? 0);
        $cyear   = $rows['invoice_counter_year'] ?? '';
        if ($cyear !== $yy) { $counter = 0; }   // با تغییر سال، شمارنده از نو شروع می‌شود
        $counter++;
        fin_set($pdo, 'invoice_counter', (string)$counter);
        fin_set($pdo, 'invoice_counter_year', $yy);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('%s-%s-%05d', $s['invoice_prefix'], $yy, $counter);
}

// =====================================================================
//  تخصیص خودکار یک پرداخت به اقساط (به ترتیب سررسید)
//  قانون «تسویه‌ی پیوسته» در صورت فعال‌بودن رعایت می‌شود.
// =====================================================================
function fin_auto_allocate($pdo, $paymentId, $caseIds, $amount) {
    $s = fin_settings($pdo);
    $sequential = $s['rule_sequential_payment'] === '1';
    $remaining = intval($amount);
    if ($remaining <= 0 || !$caseIds) return 0;

    $place = implode(',', array_fill(0, count($caseIds), '?'));
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.case_id, pi.inst_number, pi.amount, COALESCE(SUM(pa.amount),0) AS paid
        FROM policy_installments pi
        LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
        WHERE pi.case_id IN ($place)
        GROUP BY pi.id
        HAVING paid < pi.amount
        ORDER BY pi.due_date ASC, pi.case_id ASC, pi.inst_number ASC
    ");
    $stmt->execute($caseIds);
    $open = $stmt->fetchAll();

    $alloc = $pdo->prepare("INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)");
    $allocated = 0;
    $blockedCases = [];

    foreach ($open as $inst) {
        if ($remaining <= 0) break;
        // اگر قانون پیوستگی فعال است و قسطِ قبلیِ همین پرونده هنوز باز مانده، از این پرونده رد شو
        if ($sequential && isset($blockedCases[$inst['case_id']])) continue;

        $due = intval($inst['amount']) - intval($inst['paid']);
        $take = min($due, $remaining);
        if ($take <= 0) continue;

        $alloc->execute([$paymentId, $inst['id'], $take]);
        $remaining -= $take;
        $allocated += $take;

        // اگر این قسط کامل تسویه نشد و قانون پیوستگی فعال است، اقساط بعدیِ همین پرونده قفل می‌شوند
        if ($sequential && $take < $due) $blockedCases[$inst['case_id']] = true;
    }
    return $allocated;
}

// =====================================================================
//  خروجی اکسل بدون وابستگی به کتابخانه
//  از فرمت SpreadsheetML 2003 استفاده می‌شود: یک XML ساده که اکسل آن را با فرمت‌بندی
//  کامل باز می‌کند - راست‌چین، فونت B Nazanin، سربرگ بولد با پس‌زمینه، و جداکننده‌ی
//  سه‌رقمی برای ستون‌های مبلغ.
//  $numericCols: شماره‌ی ستون‌هایی (از صفر) که باید عدد و سه‌رقم‌سه‌رقم باشند.
// =====================================================================
function fin_send_excel($fileName, array $headers, array $rows, array $numericCols = []) {
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    while (ob_get_level() > 0) { ob_end_clean(); }
    header_remove('Content-Type');
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report.xls"; filename*=UTF-8\'\'' . rawurlencode($fileName . '.xls'));
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF";
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    echo '<Styles>
  <Style ss:ID="Default" ss:Name="Normal">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="RightToLeft"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="11"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
    </Borders>
  </Style>
  <Style ss:ID="hdr">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="RightToLeft" ss:WrapText="1"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#4F46E5" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#312E81"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#312E81"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#312E81"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#312E81"/>
    </Borders>
  </Style>
  <Style ss:ID="num">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="LeftToRight"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="11"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
    </Borders>
  </Style>
</Styles>' . "\n";

    echo '<Worksheet ss:Name="گزارش">' . "\n";
    echo '<Table ss:DefaultRowHeight="18">' . "\n";
    foreach ($headers as $h) {
        // طول رشته‌ی فارسی: اگر mbstring نصب نباشد، تخمین بر پایه‌ی بایت‌ها
        $len = function_exists('mb_strlen') ? mb_strlen((string)$h, 'UTF-8') : intdiv(strlen((string)$h), 2);
        $w = max(70, min(220, $len * 12 + 40));
        echo '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>' . "\n";
    }

    echo '<Row ss:Height="30">';
    foreach ($headers as $h) echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
    echo '</Row>' . "\n";

    foreach ($rows as $row) {
        echo '<Row>';
        foreach (array_values($row) as $idx => $val) {
            if (in_array($idx, $numericCols, true)) {
                echo '<Cell ss:StyleID="num"><Data ss:Type="Number">' . intval($val) . '</Data></Cell>';
            } else {
                echo '<Cell><Data ss:Type="String">' . $esc($val) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <DisplayRightToLeft/><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal>
  <TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>
</WorksheetOptions>' . "\n";
    echo '</Worksheet></Workbook>';
}

// =====================================================================
//  نرمال‌سازی شماره‌ی بیمه‌نامه برای تطبیق
//  شماره‌ها ممکن است با / یا ∕ یا خط‌تیره یا فاصله نوشته شده باشند؛ برای مقایسه فقط
//  رقم‌ها و حروف نگه داشته می‌شوند تا تفاوت‌های نگارشی باعث مغایرتِ کاذب نشود.
// =====================================================================
function fin_norm_policy($s) {
    $s = (string)$s;
    // ارقام فارسی/عربی به لاتین
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($fa, $en, $s);
    return preg_replace('/[^0-9A-Za-z]/u', '', $s);
}

// =====================================================================
//  خواندن فایل جدولی (xlsx یا csv) بدون کتابخانه‌ی بیرونی
//  xlsx در واقع یک فایل zip است؛ با ZipArchive بازش می‌کنیم و sharedStrings و sheet1
//  را مستقیم از XML می‌خوانیم. اگر نشد، null برمی‌گردانیم تا کاربر CSV بدهد.
//  خروجی: آرایه‌ای از سطرها (هر سطر آرایه‌ای از مقادیر ستون‌ها)
// =====================================================================
function fin_read_spreadsheet($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            // حذف BOM در صورت وجود
            $first = fgets($h);
            rewind($h);
            if (substr($first, 0, 3) === "\xEF\xBB\xBF") { fseek($h, 3); }
            while (($row = fgetcsv($h, 0, ',')) !== false) $rows[] = $row;
            fclose($h);
        }
        return $rows ?: null;
    }

    // xlsx نیازمند ZipArchive است. اگر روی سرور فعال نباشد، به‌جای شکستِ خاموش، با
    // خواندن مستقیم فایل zip تلاش می‌کنیم؛ و اگر آن هم نشد null برمی‌گردد تا به کاربر
    // پیام روشن بدهیم که فایل را CSV کند.
    if (!class_exists('ZipArchive')) return fin_read_xlsx_fallback($path);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return fin_read_xlsx_fallback($path);

    // رشته‌های مشترک
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
            foreach ($m[1] as $si) {
                // متن ممکن است در چند <t> تکه شده باشد
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
                $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    // نام واقعی اولین شیت
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $sheet = $zip->getFromName($sheetPath);
    if ($sheet === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') { $sheet = $zip->getFromName($n); break; }
        }
    }
    $zip->close();
    if ($sheet === false || $sheet === null) return null;

    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cell) {
                    $attr = $cell[1]; $inner = $cell[2];
                    // شماره‌ی ستون از مرجع سلول (مثل C5) استخراج می‌شود تا سلول‌های خالی جا نیفتند
                    $colIdx = 0;
                    if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rmch)) {
                        $letters = $rmch[1]; $colIdx = 0;
                        for ($i = 0; $i < strlen($letters); $i++) $colIdx = $colIdx * 26 + (ord($letters[$i]) - 64);
                        $colIdx--;
                    } else { $colIdx = count($cells); }

                    $val = '';
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                    elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = $tm2[1];

                    if (strpos($attr, 't="s"') !== false) {
                        $val = $shared[intval($val)] ?? '';
                    } else {
                        $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                    $cells[$colIdx] = $val;
                }
            }
            if ($cells) {
                ksort($cells);
                $max = max(array_keys($cells));
                $norm = [];
                for ($i = 0; $i <= $max; $i++) $norm[$i] = $cells[$i] ?? '';
                $rows[] = $norm;
            }
        }
    }
    return $rows ?: null;
}

// مسیر پشتیبان برای خواندن xlsx بدون ZipArchive: استفاده از استریمِ zip در PHP
// (روی بیشتر سرورها فعال است حتی وقتی افزونه‌ی zip نصب نیست)
function fin_read_xlsx_fallback($path) {
    if (!in_array('zip', stream_get_wrappers(), true)) return null;
    $shared = [];
    $ss = @file_get_contents('zip://' . $path . '#xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
        foreach ($m[1] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $sheet = @file_get_contents('zip://' . $path . '#xl/worksheets/sheet1.xml');
    if ($sheet === false) return null;

    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cell) {
                    $attr = $cell[1]; $inner = $cell[2];
                    $colIdx = count($cells);
                    if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rmch)) {
                        $letters = $rmch[1]; $colIdx = 0;
                        for ($i = 0; $i < strlen($letters); $i++) $colIdx = $colIdx * 26 + (ord($letters[$i]) - 64);
                        $colIdx--;
                    }
                    $val = '';
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                    elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = $tm2[1];
                    if (strpos($attr, 't="s"') !== false) $val = $shared[intval($val)] ?? '';
                    else $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $cells[$colIdx] = $val;
                }
            }
            if ($cells) {
                ksort($cells);
                $max = max(array_keys($cells));
                $norm = [];
                for ($i = 0; $i <= $max; $i++) $norm[$i] = $cells[$i] ?? '';
                $rows[] = $norm;
            }
        }
    }
    return $rows ?: null;
}

// =====================================================================
//  بایگانی مالی
//  ساختار: Archive/بایگانی/مالی/{سال}/{ماه}/[{شرکت}/]{شماره صورتحساب}/
//  صورتحسابِ «کلی» مستقیم زیر ماه قرار می‌گیرد (چون به شرکت خاصی تعلق ندارد).
// =====================================================================
function finance_archive_root($siteRoot) { return archive_root($siteRoot) . '/مالی'; }

function fin_invoice_folder($siteRoot, $period, $companyName, $invoiceNo) {
    $base = finance_archive_root($siteRoot) . '/' . $period['jalali_year'] . '/' . fin_month_name($period['jalali_month']);
    if ($companyName) $base .= '/' . sanitize_folder_name($companyName);
    return $base . '/' . sanitize_folder_name($invoiceNo);
}

// فیش‌های دریافتی (از شرکت) و پرداختی (به پاسارگاد) در دو زیرپوشه‌ی جدا نگه داشته
// می‌شوند - هم‌ساختار با پوشه‌ی صورتحساب‌ها (سال/ماه/شرکت)، برای اینکه هم‌جا پیدا شوند
function fin_receipt_folder($siteRoot, $period, $companyName, $target) {
    $base = finance_archive_root($siteRoot) . '/' . $period['jalali_year'] . '/' . fin_month_name($period['jalali_month']);
    if ($companyName) $base .= '/' . sanitize_folder_name($companyName);
    return $base . '/' . ($target === 'pasargad' ? 'فیش‌های پاسارگاد' : 'فیش‌های کارگزاری');
}

// =====================================================================
//  تبدیل عدد به حروف فارسی (برای «جمع کل به حروف» در صورتحساب)
// =====================================================================
function fin_num_to_words($num) {
    $num = (int)$num;
    if ($num === 0) return 'صفر';
    $yekan = ['','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه'];
    $dahgan = ['','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود'];
    $dahyek = ['ده','یازده','دوازده','سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده'];
    $sadgan = ['','صد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد'];
    $scales = ['', ' هزار', ' میلیون', ' میلیارد', ' هزار میلیارد'];

    $three = function($n) use ($yekan, $dahgan, $dahyek, $sadgan) {
        $parts = [];
        $s = intdiv($n, 100); $r = $n % 100;
        if ($s) $parts[] = $sadgan[$s];
        if ($r >= 10 && $r <= 19) $parts[] = $dahyek[$r - 10];
        else {
            $d = intdiv($r, 10); $y = $r % 10;
            if ($d) $parts[] = $dahgan[$d];
            if ($y) $parts[] = $yekan[$y];
        }
        return implode(' و ', $parts);
    };

    $groups = [];
    while ($num > 0) { $groups[] = $num % 1000; $num = intdiv($num, 1000); }
    $out = [];
    for ($i = count($groups) - 1; $i >= 0; $i--) {
        if ($groups[$i] === 0) continue;
        $out[] = $three($groups[$i]) . ($scales[$i] ?? '');
    }
    return implode(' و ', $out);
}

// =====================================================================
//  جمع‌آوری سطرهای صورتحساب یک دوره
//  خروجی: آرایه‌ای از پرونده‌های صادرشده‌ی آن دوره با قسط ماهانه
//  پرونده‌هایی که پرداخت مستقیم نقدی بوده‌اند، وارد صورتحساب نمی‌شوند.
// =====================================================================
function fin_collect_invoice_rows($pdo, $periodId, $companyId = null) {
    $where = ["pi.period_id = ?", "pc.status = 'ISSUED'", "COALESCE(pc.is_direct_payment,0) = 0"];
    $params = [$periodId];
    if ($companyId) { $where[] = "p.company_id = ?"; $params[] = $companyId; }

    $stmt = $pdo->prepare("
        SELECT pc.id AS case_id, pc.plate, pc.insurance_type, pc.policy_number, pc.insured_name,
               pc.total_premium, p.full_name AS holder_name, p.national_code, p.personnel_code,
               c.id AS company_id, c.name AS company_name,
               MIN(pi.amount) AS monthly_amount, COUNT(pi.id) AS inst_count
        FROM policy_installments pi
        JOIN policy_cases pc ON pi.case_id = pc.id
        JOIN persons p ON pc.person_id = p.id
        LEFT JOIN companies c ON p.company_id = c.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY pc.id
        ORDER BY c.name ASC, p.full_name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// =====================================================================
//  ساخت فایل متنی اطلاعات صورتحساب (کنار docx/pdf در بایگانی)
// =====================================================================
function fin_write_invoice_info($folder, $invoice, $rows, $companyName, $periodTitle) {
    $nl = "\r\n";
    $t  = "════════════════════════════════════════" . $nl;
    $t .= "            اطلاعات صورتحساب" . $nl;
    $t .= "════════════════════════════════════════" . $nl . $nl;
    $t .= "  شماره صورتحساب : " . $invoice['invoice_no'] . $nl;
    $t .= "  نوع            : " . ($invoice['kind'] === 'SUMMARY' ? 'کلی (کل دوره)' : 'تفکیکی') . $nl;
    $t .= "  دوره           : " . $periodTitle . $nl;
    if ($companyName) $t .= "  شرکت           : " . $companyName . $nl;
    $t .= "  تعداد ردیف     : " . count($rows) . $nl;
    $t .= "  جمع کل         : " . number_format((int)$invoice['total_amount']) . " ریال" . $nl;
    $t .= "  به حروف        : " . fin_num_to_words((int)$invoice['total_amount']) . " ریال" . $nl;
    $t .= "  تاریخ صدور     : " . jalali_from_gregorian_ts_dotted(time()) . $nl;
    $t .= $nl . "────────────────────────────────────────" . $nl;
    @file_put_contents($folder . '/اطلاعات صورتحساب.txt', "\xEF\xBB\xBF" . $t);
}

// =====================================================================
//  تولید فایل Word و PDF صورتحساب از قالب آپلودشده
//  قالب docx یک فایل zip است؛ document.xml را می‌خوانیم، کدهای {{...}} را جایگزین
//  می‌کنیم و دوباره بسته‌بندی می‌کنیم. تولید PDF به اسکریپت پایتون سپرده می‌شود
//  (همان زیرساختی که برای OCR داریم) چون کیفیت خروجی‌اش به‌مراتب بهتر است.
// =====================================================================
function fin_render_invoice_files($pdo, $invoice, $folder, $companyName, $period) {
    $siteRoot = dirname(__DIR__);
    $out = ['docx' => null, 'pdf' => null, 'note' => null];

    $stmt = $pdo->prepare("SELECT docx_path FROM invoice_templates WHERE kind = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoice['kind']]);
    $tplRel = $stmt->fetchColumn();
    if (!$tplRel) { $out['note'] = 'قالب Word برای این نوع صورتحساب بارگذاری نشده است.'; return $out; }

    $tplAbs = $siteRoot . '/' . $tplRel;
    if (!file_exists($tplAbs)) { $out['note'] = 'فایل قالب روی سرور پیدا نشد.'; return $out; }

    $lines = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id");
    $lines->execute([$invoice['id']]);
    $rows = $lines->fetchAll();

    $monthly = array_sum(array_map(fn($r) => intval($r['monthly_amount']), $rows));
    $instCount = intval(fin_settings($pdo)['default_installments']);

    $vars = [
        'شماره_صورتحساب' => $invoice['invoice_no'],
        'تاریخ_صدور'     => jalali_from_gregorian_ts_dotted(time()),
        'دوره'           => $period['title'],
        'نام_شرکت'       => $companyName ?: 'کل دوره',
        'جمع_کل'         => number_format((int)$invoice['total_amount']),
        'جمع_کل_حروف'    => fin_num_to_words((int)$invoice['total_amount']),
        'تعداد_بیمه_نامه'=> (string)($invoice['kind'] === 'SUMMARY'
                              ? array_sum(array_map(fn($r) => intval($r['policy_count']), $rows))
                              : count($rows)),
        'قسط_ماهانه'     => number_format($monthly),
        'تعداد_اقساط'    => (string)$instCount,
    ];

    $destDocx = $folder . '/صورتحساب.docx';
    $okDocx = fin_fill_docx($tplAbs, $destDocx, $vars, $invoice['kind'], $rows);
    if (!$okDocx) { $out['note'] = 'پرکردن قالب Word ممکن نشد (ZipArchive در دسترس نیست).'; return $out; }
    $out['docx'] = ltrim(str_replace($siteRoot, '', $destDocx), '/');

    // تبدیل به PDF - سه مسیر به‌ترتیب امتحان می‌شود تا یکی جواب دهد
    $destPdf = $folder . '/صورتحساب.pdf';
    $out['pdf'] = fin_docx_to_pdf($destDocx, $folder, $destPdf, $siteRoot);
    if (!$out['pdf']) {
        $out['note'] = 'فایل Word ساخته شد، ولی تبدیل خودکار به PDF ممکن نشد. می‌توانید فایل Word را باز و دستی PDF بگیرید.';
    }
    return $out;
}

// تبدیل docx به pdf - چند روش به‌ترتیب اولویت
function fin_docx_to_pdf($docxPath, $folder, $destPdf, $siteRoot) {
    if (!function_exists('shell_exec')) return null;

    // روش ۱: LibreOffice (بهترین کیفیت، اگر نصب باشد)
    foreach (['soffice', 'libreoffice'] as $bin) {
        $which = @shell_exec("command -v $bin 2>/dev/null");
        if ($which && trim($which)) {
            $home = escapeshellarg(sys_get_temp_dir());
            @shell_exec("HOME=$home " . trim($which) . ' --headless --convert-to pdf --outdir '
                        . escapeshellarg($folder) . ' ' . escapeshellarg($docxPath) . ' 2>&1');
            if (file_exists($destPdf)) return ltrim(str_replace($siteRoot, '', $destPdf), '/');
        }
    }

    // روش ۲: پایتون با docx2pdf یا docx→pdf از طریق همان محیط مجازیِ OCR
    $pythonPath = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    $converter  = '/home/besiteir/ocr-paddle/docx_to_pdf.py';
    if (file_exists($pythonPath) && file_exists($converter)) {
        @shell_exec('export PYTHONIOENCODING=utf8; ' . escapeshellarg($pythonPath) . ' '
                    . escapeshellarg($converter) . ' ' . escapeshellarg($docxPath) . ' '
                    . escapeshellarg($destPdf) . ' 2>&1');
        if (file_exists($destPdf)) return ltrim(str_replace($siteRoot, '', $destPdf), '/');
    }

    return null;
}

// پرکردن قالب docx
function fin_fill_docx($tplPath, $destPath, array $vars, $kind, array $rows) {
    if (!class_exists('ZipArchive')) return false;
    if (!@copy($tplPath, $destPath)) return false;

    $zip = new ZipArchive();
    if ($zip->open($destPath) !== true) return false;
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) { $zip->close(); return false; }

    // Word گاهی کدها را بین چند <w:t> تکه می‌کند؛ ابتدا تکه‌های مجاور را به‌هم می‌چسبانیم
    $xml = preg_replace('/<\/w:t>\s*<\/w:r>\s*<w:r[^>]*>\s*(?:<w:rPr>.*?<\/w:rPr>)?\s*<w:t[^>]*>/s', '', $xml);

    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    foreach ($vars as $k => $v) {
        $xml = str_replace('{{' . $k . '}}', $esc($v), $xml);
    }

    // ------------------------------------------------------------------
    //  جدول پویا
    //  کد یکسان {{جدول}} در همه‌ی قالب‌ها کار می‌کند و جدول بر اساس نوعِ انتخاب‌شده‌ی
    //  صورتحساب ساخته می‌شود. کدهای اختصاصیِ قدیمی هم برای سازگاری پشتیبانی می‌شوند،
    //  تا قالب‌هایی که قبلاً ساخته شده‌اند از کار نیفتند.
    // ------------------------------------------------------------------
    $tableXml = $rows ? fin_build_docx_table($kind, $rows) : '';
    $codes = ['{{جدول}}', '{{جدول_کلی}}', '{{جدول_تفکیکی}}', '{{جدول_پرسنلی}}'];
    foreach ($codes as $code) {
        if (strpos($xml, $code) === false) continue;
        // کد داخل یک پاراگراف است؛ کل آن پاراگراف با جدول جایگزین می‌شود
        $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*' . preg_quote($code, '/') . '(?:(?!<\/w:p>).)*<\/w:p>/s';
        if (preg_match($pattern, $xml)) $xml = preg_replace($pattern, $tableXml, $xml, 1);
        else $xml = str_replace($code, $tableXml, $xml);
        $tableXml = '';   // جدول فقط یک‌بار درج می‌شود؛ کدهای تکراری بعدی حذف می‌شوند
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return true;
}

// =====================================================================
//  ساخت XML جدول برای درج در docx
//  سبک جدول عیناً از نمونه‌ی صورتحسابِ شرکت گرفته شده است:
//    - سربرگ: فونت B Titr، اندازه ۸pt
//    - بدنه:  فونت B Nazanin، اندازه ۱۰pt
//    - حاشیه: تک‌خط نازک مشکی، بدون رنگ پس‌زمینه
//    - همه‌ی سلول‌ها وسط‌چین (افقی و عمودی)، راست‌به‌چپ
//  عرض هر ستون متناسب با نوع محتوایش تعیین می‌شود و اندازه‌ی فونت بر اساس تعداد ستون‌ها
//  کم می‌شود تا محتوای هر سلول در یک خط جا شود و ظاهر جدول به‌هم نریزد.
// =====================================================================
function fin_build_docx_table($kind, array $rows) {
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    // ستون‌ها: [عنوان, کلید, وزنِ عرض]  — وزن بر اساس طول معمولِ محتوا
    if ($kind === 'PERSONNEL') {
        // تلفیقی: هر بیمه‌نامه‌ی صادرشده یک ردیف، ولی فقط اطلاعات پرسنلی و مبلغ
        $cols = [
            ['ردیف',          'row_no',         5],
            ['نام پرسنل',     'person_name',   28],
            ['کد پرسنلی',     'personnel_code',13],
            ['کد ملی',        'national_code', 16],
            ['نوع بیمه‌نامه', 'insurance_type',13],
            ['مبلغ حق بیمه',  'total_premium', 20],
        ];
    } elseif ($kind === 'SUMMARY') {
        $cols = [
            ['ردیف',            'row_no',         5],
            ['نام شرکت',        'person_name',   26],
            ['تعداد بیمه‌نامه', 'policy_count',  11],
            ['جمع حق بیمه',     'total_premium', 16],
            ['قسط ماهانه',      'monthly_amount',16],
        ];
    } else {
        $cols = [
            ['ردیف',            'row_no',         4],
            ['نام بیمه‌گذار',   'person_name',   16],
            ['کد ملی',          'national_code', 12],
            ['کد پرسنلی',       'personnel_code', 8],
            ['پلاک',            'plate',         14],
            ['نوع بیمه',        'insurance_type', 7],
            ['شماره بیمه‌نامه', 'policy_number', 25],
            ['حق بیمه',         'total_premium', 12],
            ['قسط ماهانه',      'monthly_amount',12],
        ];
    }

    $n = count($cols);
    // عرض کل جدول در واحد dxa (عرض مفیدِ صفحه A4 با حاشیه‌های معمول)
    $totalW = 9900;
    $sumWeight = array_sum(array_column($cols, 2));

    // اندازه‌ی فونت بر اساس تعداد ستون‌ها، تا متن در یک خط جا شود
    // (مقادیر در نیم‌پوینت‌اند: ۱۶ یعنی ۸pt)
    if ($n <= 5)      { $hSize = 16; $bSize = 20; }
    elseif ($n <= 7)  { $hSize = 14; $bSize = 18; }
    elseif ($n <= 9)  { $hSize = 12; $bSize = 14; }
    else              { $hSize = 11; $bSize = 12; }

    $border = '<w:tcBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tcBorders>';

    // یک سلول با فونت/اندازه‌ی مشخص. noWrap مانع شکستن متن به خط دوم می‌شود.
    // $wrap: فقط برای سطر سربرگ true می‌شود تا عنوان‌های چندکلمه‌ای در صورت نیاز
    // بشکنند؛ سلول‌های داده هرگز نمی‌شکنند تا ارتفاع سطرها یکنواخت بماند
    $cell = function($text, $w, $font, $size, $bold = false, $span = 1, $wrap = false) use ($esc, $border) {
        $gridSpan = $span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '';
        $b = $bold ? '<w:b/><w:bCs/>' : '';
        $rpr = '<w:rFonts w:ascii="Arial" w:eastAsia="Times New Roman" w:hAnsi="Arial" w:cs="' . $font . '" w:hint="cs"/>'
             . $b . '<w:color w:val="000000"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
        // tcMar: حاشیه‌ی داخلی سلول را کم می‌کند تا متن فضای بیشتری داشته باشد و
        // noWrap جلوی شکستن به خط دوم را می‌گیرد
        $margins = '<w:tcMar>'
                 . '<w:top w:w="0" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/>'
                 . '<w:left w:w="28" w:type="dxa"/><w:right w:w="28" w:type="dxa"/>'
                 . '</w:tcMar>';
        return '<w:tc><w:tcPr>'
             . '<w:tcW w:w="' . $w . '" w:type="dxa"/>' . $gridSpan . $border
             . $margins . ($wrap ? '' : '<w:noWrap/>') . '<w:vAlign w:val="center"/></w:tcPr>'
             . '<w:p><w:pPr><w:bidi/><w:spacing w:after="0" w:line="60" w:lineRule="atLeast"/>'
             . '<w:jc w:val="center"/><w:rPr>' . $rpr . '</w:rPr></w:pPr>'
             . '<w:r><w:rPr>' . $rpr . '<w:rtl/></w:rPr>'
             . '<w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r></w:p></w:tc>';
    };

    $widths = [];
    foreach ($cols as $k => $cdef) $widths[$k] = (int) round($totalW * $cdef[2] / $sumWeight);

    $xml  = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:bidiVisual/>'
          . '<w:tblW w:w="' . $totalW . '" w:type="dxa"/><w:jc w:val="center"/>'
          . '<w:tblBorders>'
          . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';

    // تعریف شبکه‌ی ستون‌ها (لازم است تا Word عرض‌ها را دقیق رعایت کند)
    $xml .= '<w:tblGrid>';
    foreach ($widths as $w) $xml .= '<w:gridCol w:w="' . $w . '"/>';
    $xml .= '</w:tblGrid>';

    // سطر سربرگ (در صفحات بعدی تکرار می‌شود)
    $xml .= '<w:tr><w:trPr><w:tblHeader/><w:jc w:val="center"/></w:trPr>';
    foreach ($cols as $k => $cdef) $xml .= $cell($cdef[0], $widths[$k], 'B Titr', $hSize, true, 1, true);
    $xml .= '</w:tr>';

    // سطرهای داده
    $i = 1; $sumPremium = 0; $sumMonthly = 0;
    foreach ($rows as $r) {
        $xml .= '<w:tr><w:trPr><w:jc w:val="center"/></w:trPr>';
        foreach ($cols as $k => $cdef) {
            $key = $cdef[1];
            if ($key === 'row_no')                         $v = $i;
            elseif (in_array($key, ['total_premium', 'monthly_amount'], true))
                                                           $v = number_format((int)($r[$key] ?? 0));
            else                                           $v = $r[$key] ?? '';
            $xml .= $cell($v, $widths[$k], 'B Nazanin', $bSize);
        }
        $xml .= '</w:tr>';
        $sumPremium += (int)($r['total_premium'] ?? 0);
        $sumMonthly += (int)($r['monthly_amount'] ?? 0);
        $i++;
    }

    // سطر جمع: برچسب «جمع کل» روی ستون‌های غیرعددیِ ابتدایی ادغام می‌شود و زیر هر ستونِ
    // عددی، جمعِ همان ستون می‌نشیند. تعداد ستون‌های عددی بسته به نوع صورتحساب فرق می‌کند
    // (تلفیقی فقط «مبلغ حق بیمه» دارد، بقیه «حق بیمه» و «قسط ماهانه»).
    $numericKeys = ['total_premium', 'monthly_amount'];
    $firstNumeric = $n;
    foreach ($cols as $k => $cdef) {
        if (in_array($cdef[1], $numericKeys, true)) { $firstNumeric = $k; break; }
    }
    $spanCount = max(1, $firstNumeric);
    $spanW = 0;
    for ($k = 0; $k < $spanCount; $k++) $spanW += $widths[$k];

    $xml .= '<w:tr><w:trPr><w:jc w:val="center"/></w:trPr>';
    $xml .= $cell('جمع کل', $spanW, 'B Titr', $hSize, true, $spanCount);
    for ($k = $spanCount; $k < $n; $k++) {
        $key = $cols[$k][1];
        if ($key === 'total_premium')       $v = number_format($sumPremium);
        elseif ($key === 'monthly_amount')  $v = number_format($sumMonthly);
        else                                $v = '';
        $xml .= $cell($v, $widths[$k], 'B Titr', $hSize, true);
    }
    $xml .= '</w:tr>';

    $xml .= '</w:tbl><w:p/>';
    return $xml;
}

// تبدیل تاریخ شمسی «۱۴۰۵/۰۶/۲۱» به تاریخ میلادیِ Y-m-d برای ذخیره در ستون DATE
function fin_jalali_to_date($s) {
    $s = (string)$s;
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $s = str_replace($fa, ['0','1','2','3','4','5','6','7','8','9'], $s);
    if (!preg_match('/(\d{4})\D(\d{1,2})\D(\d{1,2})/', $s, $m)) return null;
    return date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])));
}

// اعتبارسنجی نوع صورتحساب (سه نوع پشتیبانی می‌شود)
function fin_kind($k) {
    return in_array($k, ['SUMMARY', 'DETAILED', 'PERSONNEL'], true) ? $k : 'DETAILED';
}

// عنوان فارسی نوع صورتحساب
function fin_kind_fa($k) {
    return ['SUMMARY' => 'تجمیعی (کل دوره)', 'DETAILED' => 'تفکیکی (یک شرکت)',
            'PERSONNEL' => 'تلفیقی (ریز پرسنل)'][$k] ?? $k;
}
