<?php
// فایل: api/_perm.php
// دسترسیِ سفارشیِ هر کاربرِ پنل، صفحه به صفحه: مشاهده، ثبت، ویرایش / ثبتِ مجدد، حذف، خروجی.
//  - ستونِ users.perm_json خودکار ساخته می‌شود (NULL = همان دسترسیِ پیش‌فرضِ نقش، مثلِ قبل).
//  - مدیر کل همیشه همه‌چیز را دارد و دسترسی‌اش سفارشی نمی‌شود.
//  - برای کاربرِ سفارشی، هر درخواست به API از perm_gate می‌گذرد: اگر اجازه نداشت رد می‌شود؛ اگر داشت،
//    در همان درخواست مثلِ مدیر کل رفتار می‌شود (نقش فقط در حافظه عوض می‌شود و در نشست ذخیره نمی‌شود)
//    تا بررسی‌های قدیمیِ «فقط مدیر کل» جلوی کاری را که صریحاً اجازه داده شده نگیرند.

const PERM_OPS = ['view' => 'مشاهده', 'create' => 'ثبت', 'edit' => 'ویرایش / ثبت مجدد', 'delete' => 'حذف', 'export' => 'خروجی'];

// صفحه‌های پنل (کلید = همان نامِ زبانه در switchTab) و عملیاتی که در هر کدام معنا دارد
function perm_catalog() {
    $all = ['view', 'create', 'edit', 'delete', 'export'];
    return [
        ['عمومی', [
            'dashboard' => ['داشبورد', ['view']],
            'tickets' => ['گفتگوها', ['view', 'create', 'edit', 'delete']],
        ]],
        ['کارکنان (کسر از حقوق)', [
            'records' => ['مدیریت پرونده‌ها و معرفی‌نامه‌ها', $all],
            'health' => ['بازدید سلامت و مدارک', ['view', 'edit', 'export']],
            'approved-reviews' => ['بازدیدهای تاییدشده', ['view', 'export']],
            'cases' => ['صدور بیمه‌نامه‌ی کارکنان', ['view', 'edit', 'delete', 'export']],
        ]],
        ['شرکت‌ها', [
            'companies-requests' => ['درخواست‌های شرکتی', $all],
            'companies-inbox' => ['صندوق ورودی مدارک', ['view', 'edit', 'delete']],
            'companies-manage' => ['مدیریت شرکت‌ها', $all],
        ]],
        ['مرکز صدور', [
            'issue-queue' => ['در حال صدور (ثبت صدور)', ['view', 'edit', 'export']],
            'issued-list' => ['صادره‌ها', ['view', 'edit', 'export']],
            'issue-group' => ['صدور گروهی (فایل بیمه‌گر)', ['view', 'create']],
        ]],
        ['گزارش بازدید', [
            'vr-build' => ['ساخت گزارش بازدید', ['view', 'create']],
            'vr-list' => ['گزارش‌های صادرشده', ['view', 'edit', 'delete', 'export']],
            'vr-settings' => ['تنظیمات گزارش', ['view', 'edit']],
        ]],
        ['مالی', [
            'fin-dashboard' => ['داشبورد مالی', ['view']],
            'fin-installments' => ['مرکز اقساط', $all],
            'fin-payments' => ['دریافت‌ها، پرداخت‌ها و چک‌ها', $all],
            'fin-tracking' => ['پیگیری', ['view']],
            'fin-invoices' => ['صورتحساب‌ها', $all],
            'fin-reconcile' => ['مغایرت‌گیری با اکسل', ['view', 'edit', 'export']],
            'companies-finance' => ['گزارش مالی شرکت‌ها', ['view', 'export']],
            'fin-contracts' => ['قراردادهای پرداخت', ['view', 'edit']],
            'fin-settings' => ['تنظیمات مالی', ['view', 'edit']],
        ]],
        ['بایگانی و مدیریت', [
            'filemanager' => ['بایگانی فایل‌ها', ['view', 'export']],
            'users' => ['کاربران ربات بله', ['view', 'create', 'edit', 'delete']],
            'staff-users' => ['کاربران پنل و شرکت‌ها', ['view', 'create', 'edit', 'delete']],
            'login-logs' => ['لاگ ورود و خروج', ['view', 'export']],
            'queue' => ['صف پردازش OCR', ['view', 'edit']],
            'settings' => ['تنظیمات سیستم و پشتیبان‌گیری', ['view', 'edit', 'export']],
            'announcements' => ['اعلان‌های پاپ‌آپ (ارسال برای کاربران)', ['view', 'create', 'edit', 'delete']],
        ]],
        ['اطلاعات پرسنلی', [
            'my-work' => ['کارکرد من و مرخصی', ['view', 'create', 'edit', 'export']],
            'staff-work' => ['کارکرد پرسنل، تأیید مرخصی و تنظیمات', ['view', 'edit', 'export']],
            'service-report' => ['گزارش سرویس‌های رفت‌وآمد (همه‌ی پرسنل)', ['view', 'edit']],
        ]],
    ];
}
function perm_pages() {
    static $p = null;
    if ($p === null) { $p = []; foreach (perm_catalog() as [$g, $items]) foreach ($items as $k => $v) $p[$k] = $v; }
    return $p;
}

// دسترسیِ پیش‌فرضِ هر نقش (همان چیزی که تا امروز می‌دیدند) - برای دکمه‌ی «از پیش‌فرضِ نقش» در فرم
function perm_role_defaults($role) {
    $pages = perm_pages();
    $full = function (array $keys) use ($pages) { $o = []; foreach ($keys as $k) if (isset($pages[$k])) $o[$k] = $pages[$k][1]; return $o; };
    $fin = ['fin-dashboard', 'fin-installments', 'fin-payments', 'fin-tracking', 'fin-invoices', 'fin-reconcile'];
    $mine = ['my-work' => $pages['my-work'][1]];   // «کارکرد من» برای همه
    if ($role === 'ADMIN') return $full(array_keys($pages));
    if ($role === 'PARSIAN') return ['tickets' => ['view', 'create', 'edit', 'delete'], 'vr-build' => ['view', 'create'], 'vr-list' => ['view', 'edit', 'export']] + $mine;
    if ($role === 'COMPANY_LIAISON') {
        $d = ['tickets' => ['view', 'create', 'edit', 'delete'], 'companies-requests' => ['view', 'export'], 'companies-inbox' => ['view'],
              'issued-list' => ['view', 'export'], 'companies-finance' => ['view', 'export'], 'filemanager' => ['view', 'export']];
        foreach ($fin as $k) $d[$k] = array_values(array_diff($pages[$k][1], ['delete']));   // مالی: همه جز حذف
        return $d + $mine;
    }
    $base = $full(['dashboard', 'tickets', 'records', 'health', 'approved-reviews', 'cases', 'filemanager', 'users', 'queue']);
    $base['records'] = ['view', 'create', 'edit', 'export'];
    $base['cases'] = ['view', 'edit', 'export'];
    if ($role === 'FINANCE') $base += $full($fin);
    return $base + $mine;
}

function perm_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->query("SELECT perm_json FROM users LIMIT 0"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN perm_json MEDIUMTEXT NULL"); } catch (Throwable $e2) { error_log('[perm_ensure] ' . $e2->getMessage()); } }
}

// فقط صفحه‌ها و عملیاتِ شناخته‌شده؛ هر عملیاتی جز «مشاهده» خودش «مشاهده» را هم لازم دارد
function perm_sanitize($raw) {
    $pages = perm_pages();
    $out = [];
    foreach ((array)$raw as $k => $ops) {
        if (!isset($pages[$k]) || !is_array($ops)) continue;
        $ok = array_values(array_intersect($pages[$k][1], array_map('strval', $ops)));
        if ($ok && !in_array('view', $ok, true)) array_unshift($ok, 'view');
        if ($ok) $out[$k] = $ok;
    }
    return $out;
}

// دسترسیِ سفارشیِ کاربر یا null (یعنی پیش‌فرضِ نقش)
function perm_user_custom($pdo, $uid) {
    perm_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT role, perm_json FROM users WHERE id = ?");
        $st->execute([intval($uid)]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    if (!$r || $r['role'] === 'ADMIN' || $r['perm_json'] === null || $r['perm_json'] === '') return null;
    $p = json_decode((string)$r['perm_json'], true);
    return is_array($p) ? perm_sanitize($p) : null;
}

function perm_real_role() { return $GLOBALS['PERM_REAL_ROLE'] ?? ($_SESSION['role'] ?? ''); }
function perm_is_elevated() { return !empty($GLOBALS['PERM_ELEVATED']); }

// در همین درخواست مثلِ مدیر کل؛ پیش از ذخیره‌ی نشست نقشِ واقعی برمی‌گردد
function perm_elevate() {
    if (perm_is_elevated()) return;
    $GLOBALS['PERM_REAL_ROLE'] = $_SESSION['role'] ?? '';
    $GLOBALS['PERM_ELEVATED'] = true;
    $_SESSION['role'] = 'ADMIN';
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['role'] = $GLOBALS['PERM_REAL_ROLE'];
    });
}

// $spec: «page:op|page:op|…» ؛ any = حداقل یک صفحه را ببیند ؛ fin = هر صفحه‌ی مالی
function perm_allows(array $perms, $spec) {
    foreach (explode('|', $spec) as $alt) {
        $alt = trim($alt);
        if ($alt === 'any') { if ($perms) return true; continue; }
        if ($alt === 'fin') { foreach ($perms as $k => $ops) if (strpos($k, 'fin-') === 0 || $k === 'companies-finance') return true; continue; }
        if (strpos($alt, ':') === false) continue;
        [$page, $op] = explode(':', $alt, 2);
        if (in_array($op, $perms[$page] ?? [], true)) return true;
    }
    return false;
}

// نوعِ عملیات از روی نامِ اکشن (برای اکشن‌هایی که در نقشه نیامده‌اند)
function perm_classify($action) {
    $a = strtolower((string)$action);
    if ($a === '') return 'view';
    if (preg_match('/(^|_)(delete|remove|purge|void|deactivate)(_|$)/', $a)) return 'delete';
    if (preg_match('/export|excel|zip|download/', $a)) return 'export';
    if (preg_match('/^(list|get|detail|load|search|stats|bootstrap|preview|file|history|track|meta|presence|messages|typing|init)|(_list|_detail|_file|_preview|_meta|_status)$|^(list|get)_/', $a)) return 'view';
    if (preg_match('/(^|_)(create|add|new|import|upload|issue|send|reply|register)(_|$)/', $a)) return 'create';
    return 'edit';
}

// نقشه‌ی اکشن‌های API به صفحه و عملیات
function perm_api_map() {
    $fin = 'fin';
    $req = 'companies-requests';
    return [
        'company_actions' => ['pages' => [$req], 'elevate' => true, 'actions' => [
            'finance_summary' => 'companies-finance:view|dashboard:view', 'finance_chart' => 'companies-finance:view|dashboard:view',
            'dashboard_alerts' => 'dashboard:view', 'issue_queue' => 'issue-queue:view|issued-list:view|issue-group:view',
            'save_issue_info' => 'issue-queue:edit|issued-list:edit|' . $req . ':edit', 'group_issue_requests' => 'issue-group:view',
            'issued_list' => 'issued-list:view|issue-queue:view|dashboard:view', 'list_bot_groups' => 'companies-manage:view', 'list_companies' => 'any',
            'company_import_sample' => 'companies-manage:create', 'company_import_columns' => 'companies-manage:create', 'company_import' => 'companies-manage:create',
            'create_company' => 'companies-manage:create', 'update_company' => 'companies-manage:edit', 'delete_company' => 'companies-manage:delete',
            'create_portal_user' => 'companies-manage:create|staff-users:create', 'update_portal_user' => 'companies-manage:edit|staff-users:edit',
            'delete_portal_user' => 'companies-manage:delete|staff-users:delete', 'list_portal_users' => 'companies-manage:view|staff-users:view',
            'list_requests' => $req . ':view|companies-inbox:view|issue-queue:view|issued-list:view|issue-group:view|fin-installments:view',
            'admin_create_request' => $req . ':create|issue-queue:edit', 'admin_manual_form' => $req . ':create|issue-queue:edit', 'admin_create_full_request' => $req . ':create|issue-queue:edit',
            'edit_request' => $req . ':edit', 'delete_request' => $req . ':delete',
            'admin_add_plate' => $req . ':create|' . $req . ':edit', 'admin_upload_plate_doc' => $req . ':edit|companies-inbox:edit',
            'list_request_inbox' => $req . ':view|companies-inbox:view',
            'request_detail' => $req . ':view|companies-inbox:view|issue-queue:view|issued-list:view|issue-group:view|fin-installments:view|fin-tracking:view',
            'list_inbox' => 'companies-inbox:view', 'reject_document' => 'companies-inbox:edit|' . $req . ':edit', 'assign_document' => 'companies-inbox:edit|' . $req . ':edit',
            'doc_file' => $req . ':view|companies-inbox:view|issue-queue:view|issued-list:view', 'upload_row_doc' => $req . ':edit|companies-inbox:edit|issue-queue:edit',
            'delete_row_doc' => $req . ':delete|companies-inbox:delete', 'set_row_stage' => $req . ':edit|issue-queue:edit', 'update_row' => $req . ':edit|issue-queue:edit',
            'set_row_coverages' => $req . ':edit', 'delete_row' => $req . ':delete',
            'preview_letter_rows' => $req . ':create|' . $req . ':edit', 'import_letter_rows' => $req . ':create|' . $req . ':edit', 'request_rows_text' => $req . ':view',
            'policy_file' => $req . ':view|issued-list:view|issue-queue:view', 'ocr_preview_company_policy' => 'issue-queue:edit|' . $req . ':edit',
            'bundle_analyze' => 'issue-group:create', 'bundle_recheck' => 'issue-group:create', 'bundle_page' => 'issue-group:view', 'bundle_issue' => 'issue-group:create',
            'mark_issued' => 'issue-queue:edit|' . $req . ':edit', 'list_company_installments' => $req . ':view|fin-installments:view',
            'company_receipts' => $req . ':view|fin-installments:view', 'company_pasargad_receipts' => $req . ':view|fin-installments:view',
            'create_company_payment' => 'fin-installments:create|fin-payments:create', 'settle_company_pasargad' => 'fin-installments:create|fin-payments:create',
            'retry_folder_transfer' => $req . ':edit|issued-list:edit', 'download_issued_zip' => $req . ':export|issued-list:export', 'download_folder_zip' => $req . ':export|issued-list:export',
        ]],
        'fin_plan_actions' => ['pages' => ['fin-settings'], 'elevate' => true, 'actions' => [
            'contracts' => 'any', 'contract_save' => 'fin-contracts:edit|fin-settings:edit', 'contract_delete' => 'fin-contracts:edit|fin-settings:edit', 'personnel_contract_save' => 'fin-contracts:edit|fin-settings:edit',
            'plan_get' => 'any', 'plan_save' => 'issue-queue:edit|issued-list:edit|fin-installments:edit|cases:edit|companies-requests:edit',
            'plan_reset' => 'issue-queue:edit|issued-list:edit|fin-installments:edit|cases:edit|companies-requests:edit',
            'plan_regen' => 'issue-queue:edit|issued-list:edit|fin-installments:edit|cases:edit|companies-requests:edit',
        ]],
        'finance_actions' => ['pages' => ['fin-installments'], 'elevate' => true, 'actions' => [
            'ledger_meta' => $fin . '|' . $req . ':view', 'ledger_installments' => $fin . '|' . $req . ':view', 'ledger_register' => 'fin-installments:create|fin-payments:create',
            'track' => 'fin-tracking:view|fin-installments:view|fin-payments:view', 'ledger_history' => $fin . '|' . $req . ':view', 'ledger_list' => 'fin-payments:view|fin-installments:view|fin-tracking:view',
            'ledger_receipt' => $fin . '|' . $req . ':view', 'ledger_void' => 'fin-payments:delete|fin-installments:delete', 'ledger_cheque_status' => 'fin-payments:edit|fin-installments:edit',
            'bootstrap' => $fin, 'analytics' => 'fin-dashboard:view|companies-finance:view', 'dashboard' => 'fin-dashboard:view',
            'regenerate_installments' => 'fin-installments:edit', 'get_settings' => $fin, 'save_settings' => 'fin-settings:edit',
            'tpl_list' => 'fin-invoices:view|fin-settings:view', 'tpl_preview' => 'fin-invoices:view|fin-settings:view', 'tpl_preview_file' => 'fin-invoices:view|fin-settings:view',
            'tpl_download' => 'fin-invoices:view|fin-settings:view', 'tpl_activate' => 'fin-settings:edit|fin-invoices:edit', 'tpl_starter' => 'fin-settings:edit|fin-invoices:edit',
            'upload_template' => 'fin-settings:edit|fin-invoices:edit', 'tpl_delete' => 'fin-settings:edit|fin-invoices:delete', 'file' => $fin,
            'export_installments' => 'fin-installments:export|fin-payments:export|companies-finance:export', 'export_master' => 'fin-installments:export|fin-payments:export|companies-finance:export',
            'reconcile' => 'fin-reconcile:edit', 'reconcile_status' => 'fin-reconcile:view', 'override_reconcile' => 'fin-reconcile:edit',
            'invoice_preview' => 'fin-invoices:view', 'create_invoice' => 'fin-invoices:create', 'invoices' => 'fin-invoices:view|fin-dashboard:view',
            'invoice_detail' => 'fin-invoices:view', 'deactivate_invoice' => 'fin-invoices:delete',
        ]],
        'case_actions' => ['pages' => ['cases'], 'elevate' => true, 'actions' => [
            'delete_case' => 'cases:delete|records:delete', 'admin_upload_case_doc' => 'cases:edit|health:edit|records:edit', 'set_naming' => 'cases:edit|settings:edit',
            'list' => 'cases:view|health:view|records:view', 'detail' => 'cases:view|health:view|records:view|approved-reviews:view|fin-tracking:view',
            'approve_all_docs' => 'health:edit|cases:edit', 'approve_doc' => 'health:edit|cases:edit', 'reject_doc' => 'health:edit|cases:edit', 'request_fix' => 'health:edit|cases:edit',
            'download_health_zip' => 'health:export|cases:export|approved-reviews:export|records:export', 'download_docs_zip' => 'health:export|cases:export|approved-reviews:export|records:export',
            'file' => 'cases:view|health:view|records:view|approved-reviews:view', 'upload_health_report' => 'health:edit',
            'health_list' => 'health:view', 'approved_reviews' => 'approved-reviews:view|health:view', 'approved_review_detail' => 'approved-reviews:view|health:view',
            'health_detail' => 'health:view|approved-reviews:view', 'review_health_photo' => 'health:edit', 'approve_health' => 'health:edit', 'reject_health' => 'health:edit',
            'ocr_preview_policy' => 'cases:edit', 'confirm_issue_policy' => 'cases:edit',
        ]],
        'record_actions' => ['pages' => ['records'], 'elevate' => true, 'actions' => [
            'intro_detect' => 'records:create|records:edit', 'create_intro' => 'records:create', 'create_manual' => 'records:create', 'create_manual_request' => 'records:create|issue-queue:edit',
            'manual_form_options' => 'records:view|records:create', 'stats' => 'records:view|dashboard:view', 'delete' => 'records:delete',
            'edit' => 'records:edit', 'change_status' => 'records:edit', 'create_case_for_intro' => 'records:edit|cases:edit',
            'reset_all' => 'settings:edit', 'reset_sections' => 'settings:edit',
        ]],
        'records' => ['pages' => ['records'], 'elevate' => true, 'actions' => [
            'download_intro' => 'records:view|cases:view|health:view', 'stats' => 'records:view|dashboard:view', 'export' => 'records:export|issued-list:export',
        ]],
        'queue_actions' => ['pages' => ['queue'], 'elevate' => true, 'actions' => ['list' => 'queue:view', 'reject' => 'queue:edit', 'approve' => 'queue:edit']],
        'user_actions' => ['pages' => ['users'], 'elevate' => true, 'actions' => [
            'list' => 'users:view', 'search' => 'users:view|any', 'history' => 'users:view', 'file' => 'users:view',
            'send_message' => 'users:create', 'send_file' => 'users:create', 'edit_message' => 'users:edit', 'delete_message' => 'users:delete',
        ]],
        'ticket_actions' => ['pages' => ['tickets'], 'elevate' => true, 'actions' => [
            'list' => 'tickets:view', 'messages' => 'tickets:view', 'typing' => 'tickets:view', 'file' => 'tickets:view',
            'reply' => 'tickets:create', 'send_file' => 'tickets:create', 'edit_ticket_message' => 'tickets:edit', 'delete_ticket_message' => 'tickets:delete', 'close' => 'tickets:edit',
        ]],
        // گفتگوها: خواندن (اعلان‌ها، وضعیتِ آنلاین) همیشه آزاد است؛ فقط فرستادن/ویرایش/حذف بررسی می‌شود
        'chat_actions' => ['pages' => ['tickets'], 'elevate' => false, 'loose' => true, 'actions' => [
            'chat_threads' => 'any', 'chat_contacts' => 'any', 'chat_open' => 'any', 'chat_poll' => 'any', 'chat_typing' => 'any', 'chat_ping' => 'any',
            'chat_unread' => 'any', 'chat_me' => 'any', 'chat_offline' => 'any', 'chat_set_avatar' => 'any', 'chat_hide' => 'any', 'chat_clear' => 'any',
            'chat_send' => 'tickets:create', 'chat_set_ref' => 'tickets:create', 'chat_edit' => 'tickets:edit', 'chat_close' => 'tickets:edit', 'chat_delete' => 'tickets:delete',
        ]],
        'staff_chat_actions' => ['pages' => ['tickets'], 'elevate' => false, 'loose' => true, 'actions' => ['send_message' => 'tickets:create']],
        'staff_users_actions' => ['pages' => ['staff-users'], 'elevate' => true, 'actions' => [
            'list' => 'staff-users:view|login-logs:view', 'presence' => 'staff-users:view|login-logs:view', 'login_logs' => 'login-logs:view',
            'create' => 'staff-users:create', 'update' => 'staff-users:edit', 'update_role' => 'staff-users:edit', 'delete' => 'staff-users:delete',
            'send_message' => 'staff-users:view', 'reset_requests' => 'staff-users:view', 'handle_reset' => 'staff-users:edit',
            'perm_get' => 'staff-users:view', 'perm_peek' => 'staff-users:view', 'perm_save' => 'staff-users:edit',
        ]],
        'settings_actions' => ['pages' => ['settings'], 'elevate' => true, 'actions' => [
            'get_quota' => 'any', 'get_site_url' => 'any', 'get_premium_settings' => 'any',
            'save_quota' => 'settings:edit', 'save_site_url' => 'settings:edit', 'save_premium_settings' => 'settings:edit',
        ]],
        'settings' => ['pages' => ['settings'], 'elevate' => true, 'actions' => ['save_token' => 'settings:edit', 'save_company_bot_token' => 'settings:edit']],
        'announce_actions' => ['pages' => ['announcements'], 'elevate' => false, 'actions' => [
            'pending' => 'any', 'seen' => 'any', 'ack' => 'any', 'mine' => 'any', 'list' => 'announcements:view', 'reads' => 'announcements:view', 'bday_get' => 'announcements:view',
            'save' => 'announcements:create|announcements:edit', 'toggle' => 'announcements:edit', 'resend' => 'announcements:edit', 'bday_save' => 'announcements:edit', 'delete' => 'announcements:delete',
        ]],
        'brand_actions' => ['pages' => ['settings'], 'elevate' => true, 'actions' => ['get' => 'any', 'upload' => 'settings:edit', 'remove' => 'settings:edit']],
        'backup_actions' => ['pages' => ['settings'], 'elevate' => true, 'actions' => [
            'list' => 'settings:view', 'download' => 'settings:export', 'create' => 'settings:edit', 'delete' => 'settings:edit',
            'restore' => 'settings:edit', 'upload_restore' => 'settings:edit',
        ]],
        'file_manager' => ['pages' => ['filemanager'], 'elevate' => true, 'actions' => [
            'list' => 'filemanager:view', 'search' => 'filemanager:view', 'download_url' => 'filemanager:view', 'download' => 'filemanager:view',
            'zip_folder' => 'filemanager:export', 'zip_range' => 'filemanager:export',
        ]],
        'work_actions' => ['pages' => ['my-work'], 'elevate' => false, 'actions' => [
            'settings_get' => 'any', 'month' => 'my-work:view|staff-work:view', 'day' => 'my-work:view|staff-work:view', 'report' => 'my-work:view|staff-work:view',
            'save_day' => 'my-work:edit|staff-work:edit', 'leave_add' => 'my-work:create|staff-work:edit', 'leave_delete' => 'my-work:edit|staff-work:edit',
            'profile_save' => 'my-work:edit|staff-work:edit', 'export' => 'my-work:export|staff-work:export',
            'settings_save' => 'staff-work:edit', 'leave_decide' => 'staff-work:edit', 'pending_leaves' => 'staff-work:view', 'staff_overview' => 'staff-work:view',
            'svc_month' => 'my-work:view|service-report:view', 'svc_receipt' => 'my-work:view|service-report:view', 'svc_days_save' => 'my-work:edit|service-report:edit',
            'svc_pay_save' => 'my-work:edit|service-report:edit', 'svc_pay_delete' => 'my-work:edit|service-report:edit',
            'svc_list' => 'service-report:view', 'svc_overview' => 'service-report:view', 'svc_service_receipt' => 'service-report:view', 'svc_save' => 'service-report:edit', 'svc_delete' => 'service-report:edit',
        ]],
        'dashboard_charts' => ['pages' => ['dashboard'], 'elevate' => true, 'actions' => ['' => 'dashboard:view|fin-dashboard:view']],
        'visit_reports' => ['pages' => ['vr-list'], 'elevate' => true, 'actions' => [
            'bootstrap' => 'vr-build:view|vr-list:view|vr-settings:view', 'last_trace' => 'vr-build:create|vr-list:edit|vr-settings:view',
            'parse_pdf' => 'vr-build:create|vr-list:edit', 'health_prefill' => 'vr-build:create|vr-list:edit', 'target_prefill' => 'vr-build:create|vr-list:edit',
            'preview' => 'vr-build:create|vr-list:edit|vr-settings:edit', 'test_build' => 'vr-build:create|vr-settings:edit',
            'issue' => 'vr-build:create', 'edit' => 'vr-list:edit', 'list' => 'vr-list:view', 'detail' => 'vr-list:view', 'history' => 'vr-list:view', 'file' => 'vr-list:view|vr-build:view',
            'folder_zip' => 'vr-list:export|vr-build:create', 'bundle_zip' => 'vr-list:export|vr-build:create', 'lookup_insured' => 'vr-build:create|vr-list:edit', 'plate_candidates' => 'vr-build:create|vr-list:edit', 'export_excel' => 'vr-list:export', 'delete' => 'vr-list:delete', 'restore' => 'vr-list:delete', 'purge' => 'vr-list:delete',
            'link' => 'vr-list:edit', 'unlink' => 'vr-list:edit', 'match_candidates' => 'vr-list:edit', 'rebuild_register' => 'vr-list:edit',
        ]],
    ];
}

function perm_request_action() {
    if (isset($_GET['action'])) return (string)$_GET['action'];
    if (isset($_POST['action'])) return (string)$_POST['action'];
    static $j = null;
    if ($j === null) {
        $raw = @file_get_contents('php://input');
        $j = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
    }
    return is_array($j) && isset($j['action']) ? (string)$j['action'] : '';
}

function perm_request_data() {
    static $j = null;
    if ($j === null) {
        $raw = @file_get_contents('php://input');
        $j = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
        if (!is_array($j)) $j = [];
    }
    return $j + $_POST + $_GET;
}

// ---------------- ثبتِ خودکارِ حضور و کارها برای «کارکرد من» ----------------
// هر درخواستِ پنل: «آخرین حضور» (حداکثر دقیقه‌ای یک بار). هر ثبت/ویرایش/حذف/خروجیِ موفق: یک سطر در کارهای آن روز
const PERM_TRACK_SKIP = ['ocr_preview_company_policy', 'ocr_preview_policy', 'bundle_recheck', 'preview_letter_rows', 'invoice_preview', 'tpl_preview', 'tpl_preview_file',
                         'intro_detect', 'parse_pdf', 'health_prefill', 'target_prefill', 'test_build', 'last_trace', 'typing', 'presence', 'match_candidates',
                         'company_import_columns', 'company_import_sample', 'manual_form_options', 'admin_manual_form', 'request_rows_text', 'doc_file', 'policy_file', 'file'];
function perm_activity_labels() {
    return [
        'company_actions.mark_issued' => 'ثبتِ صدورِ بیمه‌نامه‌ی شرکتی', 'company_actions.bundle_issue' => 'صدورِ گروهی از فایلِ بیمه‌گر', 'company_actions.bundle_analyze' => 'بررسیِ فایلِ صدورِ گروهی',
        'company_actions.admin_create_request' => 'ثبتِ درخواستِ شرکتی', 'company_actions.admin_create_full_request' => 'ثبتِ درخواستِ شرکتی', 'company_actions.edit_request' => 'ویرایشِ درخواستِ شرکتی',
        'company_actions.delete_request' => 'حذفِ درخواستِ شرکتی', 'company_actions.admin_add_plate' => 'افزودنِ ردیف به درخواستِ شرکتی', 'company_actions.assign_document' => 'تخصیصِ مدرک از صندوقِ ورودی',
        'company_actions.reject_document' => 'ردِ مدرکِ شرکتی', 'company_actions.upload_row_doc' => 'بارگذاریِ مدرکِ ردیف', 'company_actions.admin_upload_plate_doc' => 'بارگذاریِ مدرکِ ردیف',
        'company_actions.delete_row_doc' => 'حذفِ مدرکِ ردیف', 'company_actions.set_row_stage' => 'تغییرِ مرحله‌ی ردیف', 'company_actions.update_row' => 'ویرایشِ ردیفِ درخواست',
        'company_actions.set_row_coverages' => 'ویرایشِ پوشش‌های ردیف', 'company_actions.delete_row' => 'حذفِ ردیفِ درخواست', 'company_actions.import_letter_rows' => 'ورودِ ردیف‌ها از نامه',
        'company_actions.create_company' => 'ثبتِ شرکت', 'company_actions.update_company' => 'ویرایشِ شرکت', 'company_actions.delete_company' => 'حذفِ شرکت', 'company_actions.company_import' => 'ورودِ شرکت‌ها از اکسل',
        'company_actions.create_portal_user' => 'ساختِ کاربرِ شرکت', 'company_actions.save_issue_info' => 'ثبتِ اطلاعاتِ صدور', 'company_actions.retry_folder_transfer' => 'تلاشِ دوباره برای بایگانی',
        'company_actions.create_company_payment' => 'ثبتِ پرداختِ شرکت', 'company_actions.settle_company_pasargad' => 'تسویه با بیمه‌گر',
        'company_actions.download_issued_zip' => 'خروجیِ زیپِ صادره‌ها', 'company_actions.download_folder_zip' => 'دانلودِ پوشه‌ی مدارک',
        'finance_actions.ledger_register' => 'ثبتِ دریافت / پرداخت', 'finance_actions.ledger_void' => 'ابطالِ سندِ مالی', 'finance_actions.ledger_cheque_status' => 'تغییرِ وضعیتِ چک',
        'finance_actions.create_invoice' => 'صدورِ صورتحساب', 'finance_actions.deactivate_invoice' => 'ابطالِ صورتحساب', 'finance_actions.reconcile' => 'مغایرت‌گیری با اکسل',
        'finance_actions.override_reconcile' => 'تأییدِ دستیِ مغایرت', 'finance_actions.save_settings' => 'تغییرِ تنظیماتِ مالی', 'finance_actions.regenerate_installments' => 'بازسازیِ اقساط',
        'finance_actions.export_installments' => 'خروجیِ اکسلِ اقساط', 'finance_actions.export_master' => 'خروجیِ اکسلِ مالی', 'finance_actions.upload_template' => 'بارگذاریِ قالبِ صورتحساب',
        'visit_reports.issue' => 'صدورِ گزارشِ بازدید', 'visit_reports.edit' => 'ویرایشِ گزارشِ بازدید', 'visit_reports.delete' => 'حذفِ گزارشِ بازدید', 'visit_reports.restore' => 'بازگردانیِ گزارشِ بازدید',
        'visit_reports.export_excel' => 'خروجیِ اکسلِ گزارش‌های بازدید', 'visit_reports.folder_zip' => 'دانلودِ پوشه‌ی گزارشِ بازدید', 'visit_reports.link' => 'اتصالِ گزارشِ بازدید به درخواست',
        'case_actions.confirm_issue_policy' => 'صدورِ بیمه‌نامه‌ی کارکنان', 'case_actions.approve_doc' => 'تأییدِ مدرکِ کارکنان', 'case_actions.approve_all_docs' => 'تأییدِ همه‌ی مدارکِ کارکنان',
        'case_actions.reject_doc' => 'ردِ مدرکِ کارکنان', 'case_actions.request_fix' => 'درخواستِ اصلاحِ مدارک', 'case_actions.approve_health' => 'تأییدِ بازدیدِ سلامت',
        'case_actions.reject_health' => 'ردِ بازدیدِ سلامت', 'case_actions.review_health_photo' => 'بررسیِ عکسِ بازدیدِ سلامت', 'case_actions.upload_health_report' => 'بارگذاریِ گزارشِ بازدیدِ سلامت',
        'case_actions.delete_case' => 'حذفِ درخواستِ کارکنان', 'case_actions.admin_upload_case_doc' => 'بارگذاریِ مدرکِ کارکنان', 'case_actions.download_docs_zip' => 'دانلودِ مدارکِ کارکنان',
        'record_actions.create_intro' => 'ثبتِ معرفی‌نامه', 'record_actions.create_manual' => 'ثبتِ دستیِ پرونده', 'record_actions.create_manual_request' => 'ثبتِ دستیِ درخواستِ کارکنان',
        'record_actions.create_case_for_intro' => 'ساختِ درخواست از معرفی‌نامه', 'record_actions.edit' => 'ویرایشِ پرونده', 'record_actions.change_status' => 'تغییرِ وضعیتِ پرونده',
        'record_actions.delete' => 'حذفِ پرونده', 'record_actions.reset_all' => 'پاک‌سازیِ کلِ سامانه', 'records.export' => 'خروجیِ اکسلِ پرونده‌ها',
        'queue_actions.approve' => 'تأییدِ صفِ پردازش OCR', 'queue_actions.reject' => 'ردِ صفِ پردازش OCR',
        'staff_users_actions.create' => 'ساختِ کاربر', 'staff_users_actions.update' => 'ویرایشِ کاربر', 'staff_users_actions.delete' => 'حذفِ کاربر', 'staff_users_actions.perm_save' => 'تعیینِ دسترسیِ کاربر',
        'staff_users_actions.handle_reset' => 'رسیدگی به درخواستِ بازیابیِ رمز', 'user_actions.send_message' => 'پیام به کاربرِ ربات', 'ticket_actions.reply' => 'پاسخ به تیکت',
        'backup_actions.create' => 'پشتیبان‌گیری', 'backup_actions.restore' => 'بازگردانیِ پشتیبان', 'backup_actions.download' => 'دانلودِ فایلِ پشتیبان',
        'file_manager.zip_folder' => 'خروجیِ زیپ از بایگانی', 'file_manager.zip_range' => 'خروجیِ زیپ از بایگانی', 'settings_actions.save_quota' => 'تغییرِ تنظیماتِ سامانه',
    ];
}
function perm_activity_ref(array $d) {
    $parts = [];
    $pick = function ($k) use ($d) { $v = $d[$k] ?? null; return is_scalar($v) ? trim((string)$v) : ''; };
    if (($v = $pick('policy_number')) !== '') $parts[] = 'بیمه‌نامه ' . $v;
    if (($v = $pick('request_id')) !== '') $parts[] = 'درخواست #' . $v;
    if (($v = $pick('plate_id')) !== '') $parts[] = 'ردیف #' . $v;
    if (($v = $pick('case_id')) !== '') $parts[] = 'پرونده #' . $v;
    if (($v = $pick('amount')) !== '' && preg_match('/\d/', $v)) $parts[] = 'مبلغ ' . $v;
    foreach (['full_name', 'name', 'title'] as $k) if (($v = $pick($k)) !== '') { $parts[] = mb_substr($v, 0, 60); break; }
    if (!$parts && ($v = $pick('id')) !== '') $parts[] = '#' . $v;
    return implode(' · ', array_slice($parts, 0, 3));
}
function perm_track($pdo, $key, $action) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        require_once __DIR__ . '/_work_track.php';
        $uid = intval($_SESSION['user_id']);
        if (intval($_SESSION['_wk_seen'] ?? 0) < time() - 60) { $_SESSION['_wk_seen'] = time(); wk_touch_presence($pdo, $uid); }
        $map = perm_api_map();
        $m = $map[$key] ?? null;
        if (!$m || $key === 'work_actions' || !empty($m['loose']) || in_array($action, PERM_TRACK_SKIP, true) || $action === '') return;
        $spec = $m['actions'][$action] ?? null;
        $op = perm_classify($action);
        if ($spec !== null) {
            $first = explode('|', $spec)[0];
            $op = strpos($first, ':') !== false ? explode(':', $first)[1] : 'view';
        } elseif ($key === 'visit_reports' && strpos($action, 'set_') === 0) {
            $op = perm_classify(substr($action, 4)) === 'view' ? 'view' : 'edit';
        }
        if ($op === 'view') return;
        $page = explode(':', explode('|', $spec ?? ($m['pages'][0] . ':' . $op))[0])[0];
        $labels = perm_activity_labels();
        $label = $labels[$key . '.' . $action] ?? ((['create' => 'ثبت', 'edit' => 'ویرایش', 'delete' => 'حذف', 'export' => 'خروجی'][$op] ?? 'کار') . ' در «' . (perm_pages()[$page][0] ?? $page) . '»');
        $ref = perm_activity_ref(perm_request_data());
        if ($op === 'export') { wk_log_activity($pdo, $uid, $page, $action, $op, $label, $ref); return; }
        // فقط اگر عملیات موفق بود (پاسخ «ok: true» داد)
        ob_start();
        register_shutdown_function(function () use ($pdo, $uid, $page, $action, $op, $label, $ref) {
            $buf = ob_get_level() ? (string)ob_get_contents() : '';
            if (preg_match('/"ok"\s*:\s*true/', substr($buf, 0, 600))) wk_log_activity($pdo, $uid, $page, $action, $op, $label, $ref);
        });
    } catch (Throwable $e) { error_log('[perm_track] ' . $e->getMessage()); }
}

function perm_deny($msg) {
    if (function_exists('sec_event') && isset($GLOBALS['pdo'])) sec_event($GLOBALS['pdo'], 'PERM_DENIED', $msg);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => $msg, 'perm_denied' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// در ابتدای هر API پنل صدا زده می‌شود (بعد از اتصالِ دیتابیس)؛ برای کاربرانِ بدونِ دسترسیِ سفارشی هیچ کاری نمی‌کند
function perm_gate($pdo, $file) {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id']) || perm_is_elevated()) return;
    if (session_name() === 'bime_company_portal') return;
    $key = basename($file, '.php');
    perm_track($pdo, $key, perm_request_action());   // «کارکرد من»: حضور و کارهای انجام‌شده
    if (($_SESSION['role'] ?? '') === 'ADMIN') return;
    $map = perm_api_map();
    if (!isset($map[$key])) return;
    $perms = perm_user_custom($pdo, $_SESSION['user_id']);
    if ($perms === null) return;
    $m = $map[$key];
    $action = perm_request_action();
    if (isset($m['actions'][$action])) {
        $spec = $m['actions'][$action];
    } elseif ($key === 'visit_reports' && strpos($action, 'set_') === 0) {
        $spec = perm_classify(substr($action, 4)) === 'view' ? 'vr-settings:view' : 'vr-settings:edit';
    } else {
        $op = perm_classify($action);
        if (!empty($m['loose']) && $op === 'view') return;
        if (!empty($m['loose']) && $op === 'export') $op = 'view';
        $spec = implode('|', array_map(function ($p) use ($op) { return $p . ':' . $op; }, $m['pages']));
    }
    if (!perm_allows($perms, $spec)) {
        $first = explode('|', $spec)[0];
        $pages = perm_pages();
        $label = '';
        if (strpos($first, ':') !== false) {
            [$pg, $op] = explode(':', $first, 2);
            $label = ' («' . ($pages[$pg][0] ?? $pg) . '» - ' . (PERM_OPS[$op] ?? $op) . ')';
        }
        perm_deny('شما به این عملیات دسترسی ندارید' . $label . '. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
    }
    if (!empty($m['elevate'])) perm_elevate();
}

// برای dashboard.php: اگر کاربر دسترسیِ سفارشی دارد، صفحه با چیدمانِ مدیر کل ساخته می‌شود و
// منوها/دکمه‌ها در مرورگر بر اساسِ همین دسترسی‌ها پنهان می‌شوند
function perm_page_boot($pdo) {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'ADMIN') return ['custom' => false];
    $perms = perm_user_custom($pdo, $_SESSION['user_id']);
    if ($perms === null) return ['custom' => false];
    perm_elevate();
    return ['custom' => true, 'p' => (object)$perms];
}
