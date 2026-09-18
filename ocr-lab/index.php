<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(180);

/*
|--------------------------------------------------------------------------
| OCR LAB v0.12
|--------------------------------------------------------------------------
| v0.2 features kept:
| - image upload
| - manual 4-corner selection
| - perspective correction
| - optional rotation
| - whole-card OCR with preprocessing / language / PSM comparison
|
| v0.3 features kept:
| - manual rectangular field selection on rectified card
| - specialized OCR profiles: VIN / engine / model / digits / Persian / mixed
| - field OCR history per job
|
| v0.4 features kept:
| - multi-PSM field OCR per field type
| - automatic padded-region retries around the selected field
| - candidate consensus voting across preprocessing / PSM / padding
| - stronger model / engine validation and scoring
|
| v0.5 adds:
| - automatic structured extraction for two Iranian vehicle-card templates
| - independent OCR strategy selection per field
| - vehicle card: serial, usage, system, type, year, capacity, fuel, color, engine, VIN, room, barcode number
| - ownership/identity card: owner name, national ID, VIN and separated plate components
| - plate assembly: two digits + Persian letter + three digits + Iran code
| - whole-card fallback candidates for VIN / engine / model / year / serial
| - JSON-ready structured result with per-field validation / score / source
|
| v0.6 features kept:
| - automatic two-template detection
| - context-aware digit 2 rescue for year / plate
| - structured per-field OCR and consensus
|
| v0.7 adds:
| - two-image upload (front + back) in one request
| - no manual corner clicking: automatic card boundary trim / deskew / normalization
| - automatic side assignment even if the two uploads are swapped
| - corrected terminology: FRONT = owner/VIN/plate, BACK = technical data
| - each recognized item rendered in its own field
| - downloadable TXT + JSON containing both cards separately and full OCR text
| - raw whole-card OCR hidden from the web UI but retained for scoring/export
|
| v0.8 adds:
| - recalibrated ownership-card ROIs for the user's current near-flat photos
| - dedicated connected-component digit rescue for Persian 2/3 confusion
| - per-digit rescue for national ID, model year and plate number blocks
| - tighter plate ROIs (85 / letter / 284 / Iran 38 are isolated independently)
| - context-aware fuel canonicalization from a controlled vehicle-fuel vocabulary
| - stronger Gregorian-year preference while retaining Solar-Hijri fallback
| - cleanup of leading hyphens/noise in Latin vehicle type and Persian values
|
| v0.9 adds:
| - ownership/front-card hotfixes based on the latest real output
| - tighter owner-name ROI and stronger label-anchored whole-card fallback
| - exact 4-glyph connected-component rescue for the current legacy ID field
| - tighter Persian plate-letter ROI + whitelist of valid standard plate letters
| - left-shifted/tighter 3-digit plate ROI to prevent 2→3 confusion and Iran-box bleed
| - reduced redundant numeric OCR passes so accuracy fixes do not starve later fields
|
| v0.10 adds:
| - new-card regression fixes while preserving the previously-correct card
| - visual Persian digit-2 template rescue for national ID and model year
| - geometry-aware recovery of the thin Persian digit 1 in four-glyph model years
| - label-neighbor whole-card fallback for system / type / model year
| - canonical usage normalization (e.g. سواری-سوار → سواری-سواری)
| - safer system normalization (e.g. قولکس سید → فولکس)
| - expanded fuel OCR with PSM 10 + stricter fuel vocabulary matching
| - 9-digit side-barcode recovery when the narrow leading 1 is clipped
|
| v0.12 adds:
| - convert-only visual glyph comparison (no dependency on ImageMagick compare binary)
| - dual ۲/۳ image templates to resolve Persian digit 2 vs 3 by shape, not text substitution
| - visual ۲ rescue extended to national ID, model year, both plate digit blocks and Iran code
| - high-confidence rescue candidate scoring so visually proven digits beat repeated bad OCR consensus
| v0.12:
| - plate-number regression fix: restore component OCR as primary reader for numeric plate blocks
| - never apply generic 2/3 visual rescue to two-digit plate prefix or Iran code
| - conservative shape correction only for a leading OCR 3 in the three-digit block when the glyph is independently proven to be Persian ۲
| - prevents correct plates such as 85 ن 574 — ایران 38 from being rewritten while still repairing 384→284 when justified by the image
|--------------------------------------------------------------------------
*/

$config = [
    'tesseract' => '/home/besiteir/ocr/local/bin/tesseract',
    'tessdata' => '/home/besiteir/ocr/local/share/tessdata',
    'local_lib' => '/home/besiteir/ocr/local/lib',
    'storage' => '/home/besiteir/ocr-lab-storage',

    'max_file_size' => 8 * 1024 * 1024,
    'max_pixels' => 24000000,
    'max_ocr_runs' => 12,
    'max_rectified_dimension' => 2200,
    'preview_max_dimension' => 1600,
    'jobs_expire_seconds' => 86400,
    'max_field_ocr_runs' => 24,
    'max_auto_template_runs' => 150,
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureDir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('ساخت پوشه امکان‌پذیر نیست: ' . $path);
    }
}

function newJobId(): string
{
    return bin2hex(random_bytes(16));
}

if (empty($_SESSION['ocr_csrf'])) {
    $_SESSION['ocr_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['ocr_csrf'];

function validateCsrf(): void
{
    $session = (string)($_SESSION['ocr_csrf'] ?? '');
    $posted = (string)($_POST['csrf'] ?? '');
    if ($session === '' || $posted === '' || !hash_equals($session, $posted)) {
        throw new RuntimeException('درخواست نامعتبر است. صفحه را Refresh کن.');
    }
}

function findConvert(): string
{
    $path = trim((string)shell_exec('command -v convert 2>/dev/null'));
    if ($path === '') {
        throw new RuntimeException('ImageMagick convert پیدا نشد.');
    }
    return $path;
}

function runCommand(string $command, string $errorFile): array
{
    @unlink($errorFile);
    $output = [];
    $exitCode = 0;

    exec(
        $command . ' 2>' . escapeshellarg($errorFile),
        $output,
        $exitCode
    );

    $stderr = is_file($errorFile)
        ? trim((string)file_get_contents($errorFile))
        : '';

    return [
        'exit_code' => $exitCode,
        'stdout' => trim(implode("\n", $output)),
        'stderr' => $stderr,
    ];
}

function allowedMimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/gif' => 'gif',
        'image/tiff' => 'tif',
    ];
}

function imageDataUri(string $path, string $mime): string
{
    if (!is_file($path)) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
}

function pointDistance(float $x1, float $y1, float $x2, float $y2): float
{
    return sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function cleanupJobs(string $storage, int $expireSeconds): void
{
    if (!is_dir($storage)) {
        return;
    }

    $dirs = glob(rtrim($storage, '/') . '/*');
    if (!is_array($dirs)) {
        return;
    }

    $cutoff = time() - $expireSeconds;
    foreach ($dirs as $dir) {
        if (is_dir($dir) && (int)@filemtime($dir) < $cutoff) {
            deleteDirectory($dir);
        }
    }
}

function checkEnvironment(array $config): array
{
    $errors = [];

    if (!is_file($config['tesseract'])) {
        $errors[] = 'Tesseract پیدا نشد: ' . $config['tesseract'];
    }

    foreach (['fas', 'eng', 'osd'] as $lang) {
        $file = rtrim($config['tessdata'], '/') . '/' . $lang . '.traineddata';
        if (!is_file($file)) {
            $errors[] = 'فایل زبان پیدا نشد: ' . $file;
        }
    }

    try {
        findConvert();
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }

    return $errors;
}

function preprocessImage(
    array $config,
    string $convert,
    string $input,
    string $output,
    string $mode,
    string $errorFile
): array {
    $allowed = ['original', 'gray', 'autolevel', 'sharp', 'threshold50', 'threshold54', 'threshold56', 'threshold60', 'threshold65'];
    if (!in_array($mode, $allowed, true)) {
        throw new RuntimeException('Preprocessing نامعتبر است.');
    }

    switch ($mode) {
        case 'original':
            $filters = '-strip';
            break;
        case 'gray':
            $filters = '-colorspace Gray -strip';
            break;
        case 'autolevel':
            $filters = '-colorspace Gray -auto-level -strip';
            break;
        case 'sharp':
            $filters = '-colorspace Gray -auto-level -contrast-stretch 0.3%x0.3% -sharpen 0x0.8 -resize 160% -strip';
            break;
        case 'threshold50':
            $filters = '-colorspace Gray -auto-level -threshold 50% -strip';
            break;
        case 'threshold54':
            $filters = '-colorspace Gray -auto-level -threshold 54% -strip';
            break;
        case 'threshold56':
            $filters = '-colorspace Gray -auto-level -threshold 56% -strip';
            break;
        case 'threshold60':
            $filters = '-colorspace Gray -auto-level -threshold 60% -strip';
            break;
        case 'threshold65':
            $filters = '-colorspace Gray -auto-level -threshold 65% -strip';
            break;
        default:
            throw new RuntimeException('Preprocessing ناشناخته است.');
    }

    $cmd = escapeshellarg($convert)
        . ' ' . escapeshellarg($input)
        . ' ' . $filters
        . ' ' . escapeshellarg($output);

    return runCommand($cmd, $errorFile);
}

function runOCR(
    array $config,
    string $image,
    string $language,
    int $psm,
    string $errorFile,
    ?string $whitelist = null
): array {
    $allowedLanguages = ['fas', 'eng', 'fas+eng'];
    $allowedPSM = [3, 6, 7, 8, 10, 11, 12, 13];

    if (!in_array($language, $allowedLanguages, true)) {
        throw new RuntimeException('زبان OCR نامعتبر است.');
    }
    if (!in_array($psm, $allowedPSM, true)) {
        throw new RuntimeException('PSM نامعتبر است.');
    }

    $cmd = 'LD_LIBRARY_PATH=' . escapeshellarg($config['local_lib'])
        . ' TESSDATA_PREFIX=' . escapeshellarg($config['tessdata'])
        . ' ' . escapeshellarg($config['tesseract'])
        . ' ' . escapeshellarg($image)
        . ' stdout'
        . ' -l ' . escapeshellarg($language)
        . ' --psm ' . (int)$psm
        . ' --oem 1';

    if ($whitelist !== null && $whitelist !== '') {
        $cmd .= ' -c ' . escapeshellarg('tessedit_char_whitelist=' . $whitelist);
    }

    return runCommand($cmd, $errorFile);
}

function jsonRead(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function jsonWrite(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function assertJob(array $config, string $jobId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        throw new RuntimeException('Job ID نامعتبر است.');
    }
    if (!isset($_SESSION['ocr_jobs'][$jobId])) {
        throw new RuntimeException('این Job معتبر نیست یا Session منقضی شده است.');
    }

    $jobDir = rtrim($config['storage'], '/') . '/' . $jobId;
    if (!is_dir($jobDir)) {
        throw new RuntimeException('پوشه Job وجود ندارد.');
    }
    return $jobDir;
}

function fieldProfiles(): array
{
    return [
        'vin' => [
            'label' => 'VIN / شماره شاسی',
            'lang' => 'eng',
            'psms' => [7, 13],
            'paddings' => [0, 8],
            'auto_variants' => ['original', 'autolevel', 'threshold60'],
            'whitelist' => 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789',
        ],
        'engine' => [
            'label' => 'شماره موتور',
            'lang' => 'eng',
            'psms' => [7, 13],
            'paddings' => [0, 10],
            'auto_variants' => ['original', 'autolevel', 'threshold60'],
            'whitelist' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        ],
        'model' => [
            'label' => 'مدل / نام لاتین',
            'lang' => 'eng',
            'psms' => [7, 8, 13],
            'paddings' => [0, 18],
            'auto_variants' => ['original', 'autolevel', 'sharp'],
            'whitelist' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-',
        ],
        'digits' => [
            'label' => 'فقط عدد',
            'lang' => 'eng',
            'psms' => [7, 8, 13],
            'paddings' => [0, 10],
            'auto_variants' => ['original', 'autolevel', 'threshold60'],
            'whitelist' => '0123456789',
        ],
        'persian' => [
            'label' => 'فیلد فارسی',
            'lang' => 'fas',
            'psms' => [6, 7, 11],
            'paddings' => [0, 10],
            'auto_variants' => ['original', 'autolevel'],
            'whitelist' => null,
        ],
        'mixed' => [
            'label' => 'فارسی + انگلیسی تک‌خط',
            'lang' => 'fas+eng',
            'psms' => [6, 7, 11],
            'paddings' => [0, 10],
            'auto_variants' => ['original', 'autolevel'],
            'whitelist' => null,
        ],
        'generic' => [
            'label' => 'متن عمومی',
            'lang' => 'fas+eng',
            'psms' => [3, 6, 11],
            'paddings' => [0],
            'auto_variants' => ['original', 'autolevel'],
            'whitelist' => null,
        ],
    ];
}

function expandedCropRect(
    int $x,
    int $y,
    int $w,
    int $h,
    int $imageWidth,
    int $imageHeight,
    int $paddingPercent
): array {
    $padX = (int)round($w * ($paddingPercent / 100));
    $padY = (int)round($h * ($paddingPercent / 100));

    $nx = max(0, $x - $padX);
    $ny = max(0, $y - $padY);
    $right = min($imageWidth, $x + $w + $padX);
    $bottom = min($imageHeight, $y + $h + $padY);

    return [
        'x' => $nx,
        'y' => $ny,
        'w' => max(1, $right - $nx),
        'h' => max(1, $bottom - $ny),
        'padding' => $paddingPercent,
    ];
}

function normalizeCandidate(string $fieldType, string $text): string
{
    $text = trim($text);

    if ($fieldType === 'vin') {
        $text = strtoupper($text);
        $text = preg_replace('/[^A-Z0-9]/', '', $text) ?? '';
        return $text;
    }

    if ($fieldType === 'engine') {
        $text = strtoupper($text);
        $text = preg_replace('/[^A-Z0-9]/', '', $text) ?? '';
        return $text;
    }

    if ($fieldType === 'model') {
        $text = strtoupper($text);
        $text = preg_replace('/[^A-Z0-9\- ]/', '', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text);
    }

    if ($fieldType === 'digits') {
        return preg_replace('/[^0-9]/', '', $text) ?? '';
    }

    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    return trim($text);
}

function candidateScore(string $fieldType, string $normalized): int
{
    if ($normalized === '') {
        return -1000;
    }

    if ($fieldType === 'vin') {
        $len = strlen($normalized);
        $score = $len * 4;
        if ($len === 17) {
            $score += 120;
        } else {
            $score -= abs(17 - $len) * 15;
        }
        if (preg_match('/^[A-HJ-NPR-Z0-9]+$/', $normalized)) {
            $score += 20;
        }
        return $score;
    }

    if ($fieldType === 'engine') {
        $len = strlen($normalized);
        $score = $len * 4;
        if ($len >= 6 && $len <= 16) {
            $score += 40;
        } else {
            $score -= abs(10 - $len) * 4;
        }
        if (preg_match('/[A-Z]/', $normalized) && preg_match('/[0-9]/', $normalized)) {
            $score += 35;
        }
        if (preg_match('/^[A-Z0-9]+$/', $normalized)) {
            $score += 10;
        }
        return $score;
    }

    if ($fieldType === 'digits') {
        $len = strlen($normalized);
        return $len * 6 + ($len >= 4 ? 20 : 0);
    }

    if ($fieldType === 'model') {
        $compact = str_replace([' ', '-'], '', $normalized);
        $len = strlen($compact);
        preg_match_all('/[A-Z]/', $compact, $letters);
        preg_match_all('/[0-9]/', $compact, $digits);
        $letterCount = count($letters[0] ?? []);
        $digitCount = count($digits[0] ?? []);

        $score = $len * 5 + $letterCount * 7;
        if ($letterCount >= 4 && $len >= 4 && $len <= 24) {
            $score += 70;
        }
        if ($letterCount < 4) {
            $score -= (4 - $letterCount) * 35;
        }
        if (substr_count($normalized, '-') > 1) {
            $score -= 25;
        }
        if ($digitCount > 6) {
            $score -= 20;
        }
        return $score;
    }

    if ($fieldType === 'persian') {
        $matches = [];
        $count = preg_match_all('/[\x{0600}-\x{06FF}]/u', $normalized, $matches);
        return (int)$count * 5 + mb_strlen($normalized, 'UTF-8');
    }

    return mb_strlen($normalized, 'UTF-8');
}

function fieldValidation(string $fieldType, string $normalized): array
{
    if ($fieldType === 'vin') {
        $valid = strlen($normalized) === 17
            && (bool)preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $normalized);
        return [
            'valid' => $valid,
            'message' => $valid ? 'VIN از نظر طول و کاراکترها معتبر است.' : 'VIN باید 17 کاراکتر و بدون I/O/Q باشد.',
        ];
    }

    if ($fieldType === 'engine') {
        $valid = strlen($normalized) >= 6
            && strlen($normalized) <= 20
            && (bool)preg_match('/[A-Z]/', $normalized)
            && (bool)preg_match('/[0-9]/', $normalized);
        return [
            'valid' => $valid,
            'message' => $valid
                ? 'شماره موتور از نظر طول و ترکیب حروف/اعداد قابل قبول است.'
                : 'برای شماره موتور انتظار ترکیب حروف و اعداد با طول منطقی داریم.',
        ];
    }

    if ($fieldType === 'model') {
        $compact = str_replace([' ', '-'], '', $normalized);
        preg_match_all('/[A-Z]/', $compact, $letters);
        $letterCount = count($letters[0] ?? []);
        $valid = strlen($compact) >= 4 && strlen($compact) <= 30 && $letterCount >= 4;
        return [
            'valid' => $valid,
            'message' => $valid
                ? 'نام/مدل لاتین از نظر پایه قابل قبول است.'
                : 'خروجی مدل کوتاه یا مشکوک است؛ محدوده را کمی دقیق‌تر انتخاب کن یا Auto را اجرا کن.',
        ];
    }

    if ($fieldType === 'digits') {
        $valid = $normalized !== '' && ctype_digit($normalized);
        return [
            'valid' => $valid,
            'message' => $valid ? 'خروجی فقط عدد است.' : 'خروجی عددی معتبر نیست.',
        ];
    }

    return [
        'valid' => $normalized !== '',
        'message' => $normalized !== '' ? 'متن استخراج شد.' : 'متنی استخراج نشد.',
    ];
}


/*
|--------------------------------------------------------------------------
| v0.5 Structured template extraction helpers
|--------------------------------------------------------------------------
*/

function faDigitsToEn(string $text): string
{
    return strtr($text, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

function normalizeFaText(string $text): string
{
    $text = str_replace([
        "\xE2\x80\x8E", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAB", "\xE2\x80\xAC", "\xE2\x80\xAD", "\xE2\x80\xAE"
    ], '', $text);
    $text = str_replace(['ي','ى','ك','ۀ','ة'], ['ی','ی','ک','ه','ه'], $text);
    $text = faDigitsToEn($text);
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    return trim($text);
}

function persianCharCount(string $text): int
{
    $m = [];
    return (int)preg_match_all('/[\x{0600}-\x{06FF}]/u', $text, $m);
}

function wholeTextCombined(array $wholeResults): string
{
    $chunks = [];
    foreach ($wholeResults as $row) {
        $t = trim((string)($row['text'] ?? ''));
        if ($t !== '') {
            $chunks[] = $t;
        }
    }
    return implode("\n", $chunks);
}

function cardTemplates(): array
{
    /*
     * ROI coordinates are fractions of the rectified card.
     * They are based on the two reference layouts supplied during development.
     * Each ROI targets the VALUE area, not the Persian label whenever possible.
     */
    return [
        'vehicle_card' => [
            'label' => 'پشت کارت خودرو — مشخصات فنی',
            'detect' => ['کاربری', 'شماره موتور', 'شماره شاسی', 'نوع سوخت', 'شماره اتاق', 'سیستم', 'تیپ'],
            'fields' => [
                'serial_number' => [
                    'label' => 'شماره مسلسل', 'kind' => 'serial',
                    'roi' => [0.025, 0.18, 0.085, 0.75], 'rotations' => [90, -90],
                ],
                'usage' => [
                    'label' => 'کاربری', 'kind' => 'usage',
                    'roi' => [0.53, 0.015, 0.20, 0.115], 'remove' => ['کاربری'],
                ],
                'system' => [
                    'label' => 'سیستم', 'kind' => 'system',
                    'roi' => [0.56, 0.105, 0.18, 0.115], 'remove' => ['سیستم'],
                ],
                'vehicle_type' => [
                    'label' => 'تیپ', 'kind' => 'latin_word',
                    'roi' => [0.53, 0.19, 0.22, 0.115], 'remove' => ['تیپ'],
                ],
                'model_year' => [
                    'label' => 'مدل', 'kind' => 'year',
                    'roi' => [0.58, 0.29, 0.15, 0.09], 'remove' => ['مدل'],
                    'digit_rescue' => true, 'digit_count' => 4,
                ],
                'capacity' => [
                    'label' => 'ظرفیت', 'kind' => 'capacity',
                    'roi' => [0.48, 0.375, 0.27, 0.13], 'remove' => ['ظرفیت'],
                ],
                'fuel' => [
                    'label' => 'نوع سوخت', 'kind' => 'fuel',
                    'roi' => [0.50, 0.485, 0.22, 0.12], 'remove' => ['نوع سوخت','سوخت'],
                ],
                'color' => [
                    'label' => 'رنگ', 'kind' => 'color',
                    'roi' => [0.43, 0.575, 0.29, 0.13], 'remove' => ['رنگ'],
                ],
                'engine_number' => [
                    'label' => 'شماره موتور', 'kind' => 'engine',
                    'roi' => [0.39, 0.655, 0.30, 0.125], 'remove' => ['شماره موتور'],
                ],
                'vin' => [
                    'label' => 'شماره شاسی / VIN', 'kind' => 'vin',
                    'roi' => [0.17, 0.745, 0.50, 0.125], 'remove' => ['شماره شاسی','VIN'],
                ],
                'room_number' => [
                    'label' => 'شماره اتاق', 'kind' => 'room',
                    'roi' => [0.55, 0.86, 0.17, 0.12], 'remove' => ['شماره اتاق'],
                ],
                'barcode_number' => [
                    'label' => 'شماره زیر بارکد', 'kind' => 'barcode',
                    'roi' => [0.915, 0.31, 0.060, 0.47], 'rotations' => [90, -90], 'leading_one_rescue' => true,
                ],
            ],
        ],

        'ownership_card' => [
            'label' => 'روی کارت خودرو — مالک / VIN / پلاک',
            'detect' => ['نام مالک', 'شماره پلاک', 'شناسه ملی', 'شماره ملی', 'کارت شناسایی', 'VIN'],
            'fields' => [
                'owner_name' => [
                    'label' => 'نام مالک', 'kind' => 'owner_name',
                    // Value-only crop: avoids the blue header and most of the printed label.
                    'roi' => [0.56, 0.25, 0.26, 0.12], 'remove' => ['نام مالک','مالک'],
                ],
                'national_id' => [
                    'label' => 'کد ملی / شناسه ملی', 'kind' => 'national_id',
                    'roi' => [0.39, 0.42, 0.19, 0.17], 'remove' => ['شماره ملی','شناسه ملی','شماره شناسه ملی'],
                    // This legacy ownership-card layout prints a 4-glyph identifier here.
                    // Force four connected components instead of trusting a bad one-digit OCR candidate.
                    'digit_rescue' => true,
                    'digit_count' => 4,
                ],
                'vin' => [
                    'label' => 'VIN', 'kind' => 'vin',
                    'roi' => [0.16, 0.59, 0.49, 0.15], 'remove' => ['VIN'],
                ],
                'plate_two_digits' => [
                    'label' => 'پلاک — دو رقم', 'kind' => 'plate_two',
                    'roi' => [0.455, 0.755, 0.095, 0.17],
                    'digit_rescue' => true, 'digit_count' => 2,
                ],
                'plate_letter' => [
                    'label' => 'پلاک — حرف', 'kind' => 'plate_letter',
                    // Tight value-only crop. Keeping the plate border out greatly helps ن vs ا.
                    'roi' => [0.525, 0.755, 0.060, 0.165],
                ],
                'plate_three_digits' => [
                    'label' => 'پلاک — سه رقم', 'kind' => 'plate_three',
                    // Shift left and tighten: the old ROI clipped the first Persian ۲ and bled into the Iran box.
                    'roi' => [0.580, 0.755, 0.140, 0.17],
                    'digit_rescue' => true, 'digit_count' => 3,
                ],
                'plate_iran_code' => [
                    'label' => 'پلاک — ایران', 'kind' => 'plate_iran',
                    'roi' => [0.725, 0.79, 0.095, 0.14], 'remove' => ['ایران'],
                    'digit_rescue' => true, 'digit_count' => 2,
                ],
            ],
        ],
    ];
}

function autoProfile(string $kind): array
{
    // Standard white/private Iranian plate letters. Excluding invalid lookalikes such as ا
    // prevents a missing dot on ن from being accepted as a valid final result.
    $persianPlateLetters = 'بجدسصطقلمنوهی';

    switch ($kind) {
        case 'vin':
            return ['langs'=>['eng'], 'psms'=>[7,13], 'variants'=>['original','autolevel'], 'whitelist'=>'ABCDEFGHJKLMNPRSTUVWXYZ0123456789'];
        case 'engine':
            return ['langs'=>['eng'], 'psms'=>[7,13], 'variants'=>['original','autolevel'], 'whitelist'=>'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'];
        case 'latin_word':
            return ['langs'=>['eng'], 'psms'=>[7,8,13], 'variants'=>['original','autolevel'], 'whitelist'=>'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-'];
        case 'year':
            return ['langs'=>['fas'], 'psms'=>[7,10,13], 'variants'=>['autolevel','threshold50','threshold54'], 'whitelist'=>null];
        case 'serial':
            return ['langs'=>['eng'], 'psms'=>[7,13], 'variants'=>['original','autolevel'], 'whitelist'=>'0123456789'];
        case 'barcode':
            return ['langs'=>['eng'], 'psms'=>[6,7,13], 'variants'=>['original','autolevel','sharp'], 'whitelist'=>'0123456789'];
        case 'national_id':
            // Component rescue is the primary reader here; keep ordinary passes lean.
            return ['langs'=>['fas'], 'psms'=>[7,13], 'variants'=>['original','autolevel'], 'whitelist'=>null];
        case 'plate_two':
        case 'plate_iran':
            return ['langs'=>['fas'], 'psms'=>[8,13], 'variants'=>['original','autolevel'], 'whitelist'=>null];
        case 'plate_three':
            return ['langs'=>['fas'], 'psms'=>[7,8,13], 'variants'=>['original','autolevel','threshold56'], 'whitelist'=>null];
        case 'plate_letter':
            return ['langs'=>['fas'], 'psms'=>[10,13], 'variants'=>['original','autolevel','threshold60'], 'whitelist'=>$persianPlateLetters];
        case 'fuel':
            return ['langs'=>['fas'], 'psms'=>[7,10,11], 'variants'=>['autolevel','sharp','threshold50'], 'whitelist'=>null];
        case 'usage':
        case 'system':
        case 'capacity':
        case 'color':
        case 'owner_name':
            return ['langs'=>['fas'], 'psms'=>[7,11], 'variants'=>['original','autolevel'], 'whitelist'=>null];
        case 'room':
            return ['langs'=>['fas+eng'], 'psms'=>[7,13], 'variants'=>['original','autolevel'], 'whitelist'=>null];
        default:
            return ['langs'=>['fas+eng'], 'psms'=>[7,11], 'variants'=>['original','autolevel'], 'whitelist'=>null];
    }
}

function bestDigitSequence(string $text, array $expectedLengths = []): string
{
    $text = faDigitsToEn($text);
    $matches = [];
    preg_match_all('/[0-9]+/', $text, $matches);
    $seqs = $matches[0] ?? [];
    if (!$seqs) {
        return '';
    }

    usort($seqs, function (string $a, string $b) use ($expectedLengths): int {
        $score = function (string $s) use ($expectedLengths): int {
            $len = strlen($s);
            $v = $len;
            foreach ($expectedLengths as $expected) {
                if ($len === (int)$expected) {
                    $v += 100;
                } else {
                    $v -= abs($len - (int)$expected) * 5;
                }
            }
            return $v;
        };
        $sa = $score($a);
        $sb = $score($b);
        return $sa === $sb ? 0 : ($sa > $sb ? -1 : 1);
    });

    return (string)$seqs[0];
}

function bestPersianValue(string $text, array $remove = [], string $kind = ''): string
{
    $text = normalizeFaText($text);
    $lines = preg_split('/\R+/u', $text) ?: [$text];
    $candidates = [];

    foreach ($lines as $line) {
        $line = normalizeFaText((string)$line);
        foreach ($remove as $label) {
            $line = str_replace(normalizeFaText((string)$label), ' ', $line);
        }
        $line = preg_replace('/[^\x{0600}-\x{06FF}0-9A-Za-z\- ]/u', ' ', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = trim($line, " \t\n\r\0\x0B:-");
        if ($kind === 'owner_name' && mb_strpos($line, 'گروه') !== false) {
            $line = preg_replace('/^.*?(گروه\s+)/u', '$1', $line) ?? $line;
            $line = trim($line);
        }
        if ($line === '') {
            continue;
        }

        $pc = persianCharCount($line);
        $score = $pc * 7 + mb_strlen($line, 'UTF-8');
        if ($kind === 'usage' && mb_strpos($line, 'سواری') !== false) $score += 120;
        if ($kind === 'capacity' && mb_strpos($line, 'نفر') !== false) $score += 100;
        if ($kind === 'capacity' && preg_match('/[0-9]/', faDigitsToEn($line))) $score += 25;
        if ($kind === 'fuel' && preg_match('/بنزین|گازوئیل|گازوییل|هیبرید|برقی|دوگانه|سی.?ان.?جی/u', $line)) $score += 130;
        if ($kind === 'color' && mb_strpos($line, 'متالیک') !== false) $score += 110;
        if ($kind === 'owner_name' && $pc >= 4) $score += 70;
        if ($kind === 'system' && $pc >= 3) $score += 40;

        $candidates[] = ['value'=>$line, 'score'=>$score];
    }

    if (!$candidates) return '';
    usort($candidates, function (array $a, array $b): int {
        return (int)$a['score'] === (int)$b['score'] ? 0 : ((int)$a['score'] > (int)$b['score'] ? -1 : 1);
    });
    return (string)$candidates[0]['value'];
}


function isPlausibleVehicleYear(string $value): bool
{
    if (strlen($value) !== 4 || !ctype_digit($value)) return false;
    $n = (int)$value;
    return ($n >= 1300 && $n <= 1500) || ($n >= 1900 && $n <= 2100);
}

function repairYearDigitTwo(string $text): string
{
    $value = bestDigitSequence($text, [4]);
    if ($value === '') return '';

    /* Four digits are easy to validate. The legacy Persian ۲ is often read as ۳,
       so try 3→2 substitutions before accepting a strange value. */
    if (strlen($value) === 4) {
        $n = (int)$value;
        if ($n >= 1900 && $n <= 2100) return $value;

        $candidates = [];
        for ($i = 0; $i < 4; $i++) {
            if ($value[$i] === '3') {
                $c = $value;
                $c[$i] = '2';
                $candidates[] = $c;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            $cn = (int)$candidate;
            if ($cn >= 1900 && $cn <= 2100) return $candidate;
        }

        /* Solar-Hijri remains supported, but only for a genuine four-digit OCR. */
        if ($n >= 1300 && $n <= 1500) return $value;
        return $value;
    }

    /* Do NOT turn a three-digit OCR such as ۳۰۸ into ۱۳۰۸. In this font the
       narrow Persian ۱ is frequently dropped. The geometry rescue later sees
       the four connected glyphs and reconstructs the missing ۱ safely. */
    return $value;
}

function extractAllDigitSequences(string $text): array
{
    $text = faDigitsToEn($text);
    $m = [];
    preg_match_all('/[0-9]+/', $text, $m);
    return array_values(array_filter((array)($m[0] ?? []), function ($v): bool {
        return (string)$v !== '';
    }));
}

function firstSingleDigitFromCandidates(array $item): string
{
    foreach ((array)($item['candidates'] ?? []) as $candidate) {
        foreach (extractAllDigitSequences((string)($candidate['raw'] ?? '')) as $seq) {
            if (strlen($seq) === 1) return $seq;
        }
    }
    return '';
}

function repairOwnershipPlateFields(array $fields): array
{
    if (!isset($fields['plate_three_digits'])) return $fields;

    $three = (string)($fields['plate_three_digits']['best']['normalized'] ?? '');
    if (strlen($three) === 2 && isset($fields['plate_letter'])) {
        $prefix = firstSingleDigitFromCandidates((array)$fields['plate_letter']);
        if ($prefix !== '') {
            $repaired = $prefix . $three;
            if (strlen($repaired) === 3 && ctype_digit($repaired)) {
                $old = (array)($fields['plate_three_digits']['best'] ?? []);
                $old['normalized'] = $repaired;
                $old['raw'] = $repaired;
                $old['source'] = 'cross-field-digit2-rescue';
                $old['variant'] = 'plate-boundary-fusion';
                $old['psm'] = 'fusion';
                $old['score'] = max(320, (int)($old['score'] ?? 0) + 120);
                $old['consensus'] = max(1, (int)($old['consensus'] ?? 0));
                $fields['plate_three_digits']['best'] = $old;
                $fields['plate_three_digits']['validation'] = validateAutoValue('plate_three', $repaired);
            }
        }
    }
    return $fields;
}

function unicodeChars(string $text): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? $chars : [];
}

function unicodeLevenshteinDistance(string $a, string $b): int
{
    $aa = unicodeChars($a);
    $bb = unicodeChars($b);
    $n = count($aa);
    $m = count($bb);
    if ($n === 0) return $m;
    if ($m === 0) return $n;

    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $aa[$i - 1] === $bb[$j - 1] ? 0 : 1;
            $cur[$j] = min(
                $cur[$j - 1] + 1,
                $prev[$j] + 1,
                $prev[$j - 1] + $cost
            );
        }
        $prev = $cur;
    }
    return (int)$prev[$m];
}

function compactPersianMatchText(string $text): string
{
    $text = normalizeFaText($text);
    $text = preg_replace('/[^\x{0600}-\x{06FF}A-Za-z0-9]/u', '', $text) ?? $text;
    return trim($text);
}

function canonicalFuelValue(string $text): string
{
    $base = bestPersianValue($text, ['نوع سوخت','سوخت'], 'fuel');
    $haystack = compactPersianMatchText($base !== '' ? $base : $text);
    if ($haystack === '') return $base;

    $vocabulary = [
        'بنزین' => ['بنزین','بفزین','بنرین','بزین','نرین','ترین','نیزنب','نیزیپ','نیزفپ'],
        'گازوئیل' => ['گازوئیل','گازوییل'],
        'دوگانه‌سوز' => ['دوگانهسوز','دوگانه سوز'],
        'هیبرید' => ['هیبرید'],
        'برقی' => ['برقی'],
        'CNG' => ['cng','سیانجی','سی ان جی'],
        'LPG' => ['lpg','الپیجی','ال پی جی'],
    ];

    $best = '';
    $bestDistance = PHP_INT_MAX;
    $bestTargetLen = 0;
    foreach ($vocabulary as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            $target = compactPersianMatchText((string)$alias);
            if ($target === '') continue;
            if ($haystack === $target || mb_strpos($haystack, $target) !== false || mb_strpos($target, $haystack) !== false) {
                return $canonical;
            }
            $distance = unicodeLevenshteinDistance($haystack, $target);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string)$canonical;
                $bestTargetLen = max(1, count(unicodeChars($target)));
            }
        }
    }

    /* Be deliberately stricter than v0.9. Short garbage such as "بر بل" must
       not become "برقی" merely because both are short words. */
    $limit = max(1, (int)floor($bestTargetLen * 0.35));
    if ($best !== '' && $bestDistance <= $limit) return $best;
    return $base;
}

function canonicalUsageValue(string $text): string
{
    $base = bestPersianValue($text, ['کاربری'], 'usage');
    $compact = compactPersianMatchText($base !== '' ? $base : $text);
    if ($compact === '') return $base;

    $map = [
        'سواری-سواری' => ['سواريسواري','سواريسوار','سوارسوار','سواریسواری','سواریسوار'],
        'سواری-کار' => ['سواریکار','سواريکار','سواری کار'],
        'وانت' => ['وانت'],
        'کامیونت' => ['کامیونت'],
        'کامیون' => ['کامیون'],
        'اتوبوس' => ['اتوبوس'],
        'مینی‌بوس' => ['مینيبوس','مینیبوس'],
    ];
    foreach ($map as $canonical=>$aliases) {
        foreach ($aliases as $alias) {
            $target = compactPersianMatchText((string)$alias);
            if ($target !== '' && ($compact === $target || mb_strpos($compact, $target) !== false || mb_strpos($target, $compact) !== false)) {
                return $canonical;
            }
        }
    }

    if (mb_strpos($compact, 'سواری') !== false || mb_strpos($compact, 'سواري') !== false) {
        if (mb_strpos($compact, 'کار') !== false) return 'سواری-کار';
        if (mb_strpos($compact, 'سوار') !== false) return 'سواری-سواری';
    }
    return preg_replace('/\s*[-–—]\s*/u', '-', $base) ?? $base;
}

function canonicalSystemValue(string $text): string
{
    $base = bestPersianValue($text, ['سیستم'], 'system');
    $norm = normalizeFaText($base !== '' ? $base : $text);
    if ($norm === '') return $base;

    $brands = [
        'فولکس' => ['فولکس','قولکس','فولکسر','فولگس'],
        'پژو' => ['پژو'], 'رنو' => ['رنو'], 'تویوتا' => ['تویوتا'],
        'هیوندای' => ['هیوندای'], 'کیا' => ['کیا'], 'نیسان' => ['نیسان'],
        'مزدا' => ['مزدا'], 'بنز' => ['بنز'], 'سایپا' => ['سایپا'],
        'ایران خودرو' => ['ایران خودرو','ایرانخودرو'], 'سوزوکی' => ['سوزوکی'],
        'میتسوبیشی' => ['میتسوبیشی'], 'چری' => ['چری'], 'جک' => ['جک'],
        'لیفان' => ['لیفان'], 'ولوو' => ['ولوو'], 'اسکانیا' => ['اسکانیا'], 'مان' => ['مان'],
    ];

    $tokens = preg_split('/\s+/u', $norm) ?: [$norm];
    foreach ($tokens as $token) {
        $c = compactPersianMatchText((string)$token);
        foreach ($brands as $canonical=>$aliases) {
            foreach ($aliases as $alias) {
                $a = compactPersianMatchText((string)$alias);
                if ($c !== '' && ($c === $a || unicodeLevenshteinDistance($c, $a) <= 1)) return $canonical;
            }
        }
    }
    foreach ($brands as $canonical=>$aliases) {
        foreach ($aliases as $alias) {
            $a = compactPersianMatchText((string)$alias);
            $c = compactPersianMatchText($norm);
            if ($a !== '' && ($c === $a || mb_strpos($c, $a) !== false)) return $canonical;
        }
    }

    /* When OCR appends a watermark/noise token, prefer the first substantial word. */
    foreach ($tokens as $token) {
        if (persianCharCount((string)$token) >= 3) return trim((string)$token);
    }
    return $base;
}

function normalizeSinglePersianDigit(string $text): string
{
    $digits = bestDigitSequence($text, [1]);
    if ($digits !== '') return substr($digits, 0, 1);

    $fa = normalizeFaText($text);
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
    foreach ($map as $char => $digit) {
        if (mb_strpos($fa, $char) !== false) return $digit;
    }
    return '';
}

function digitComponentRescueCandidate(
    array $config,
    string $convert,
    string $baseCrop,
    string $cropBase,
    int $expectedCount,
    string $kind,
    int &$runCounter
): ?array {
    if ($expectedCount < 1 || $expectedCount > 12 || !is_file($baseCrop)) return null;

    /* Detect glyph components on a binary copy, but OCR each glyph from the
       ORIGINAL crop. This is important for Persian ۲, which Tesseract often
       turns into ۳ when the glyph is over-cropped or thresholded too hard. */
    $binary = $cropBase . '-digit-components.png';
    $binCmd = escapeshellarg($convert) . ' ' . escapeshellarg($baseCrop)
        . ' -colorspace Gray -auto-level -threshold 56% -strip ' . escapeshellarg($binary);
    runCommand($binCmd, $cropBase . '-digit-components-prep.txt');
    if (!is_file($binary)) return null;

    $ccErr = $cropBase . '-digit-components-cc.txt';
    $cc = runCommand(
        escapeshellarg($convert) . ' ' . escapeshellarg($binary)
        . ' -define connected-components:verbose=true -connected-components 8 null:',
        $ccErr
    );
    $verbose = trim((string)$cc['stdout'] . "\n" . (string)$cc['stderr']);
    if ($verbose === '') return null;

    $components = [];
    foreach (preg_split('/\R/u', $verbose) ?: [] as $line) {
        $m = [];
        if (!preg_match('/^\s*\d+:\s+(\d+)x(\d+)\+(\d+)\+(\d+).*?\s+(\d+)\s+gray\(0(?:\.0+)?\)\s*$/i', trim((string)$line), $m)) {
            continue;
        }
        $w = (int)$m[1]; $h = (int)$m[2]; $x = (int)$m[3]; $y = (int)$m[4]; $area = (int)$m[5];
        if ($w < 3 || $h < 5 || $area < 8) continue;
        $components[] = ['x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'area'=>$area];
    }
    if (count($components) < $expectedCount) return null;

    /* The value-area crops are intentionally tight. The N largest dark
       connected components are therefore the N printed digits, including the
       tiny Persian zero (۰) in years such as ۲۰۱۸. */
    usort($components, function(array $a, array $b): int {
        return (int)$b['area'] <=> (int)$a['area'];
    });
    $selected = array_slice($components, 0, $expectedCount);
    usort($selected, function(array $a, array $b): int {
        return (int)$a['x'] <=> (int)$b['x'];
    });

    $digits = [];
    $rawParts = [];
    foreach ($selected as $idx => $component) {
        $votes = [];
        $trialRaw = [];

        /* Several padding widths deliberately change the context around one
           glyph. For the fixed Iranian card font, a true ۲ is frequently read
           as ۳ in tight crops but becomes ۲ with a little more side context.
           A real ۳ stays ۳ across these trials. */
        foreach ([2, 5, 8, 10] as $pad) {
            foreach (['original','threshold45'] as $mode) {
                if ($runCounter >= (int)$config['max_auto_template_runs']) break 2;
                $runCounter++;

                $bx = max(0, (int)$component['x'] - $pad);
                $by = max(0, (int)$component['y'] - $pad);
                $bw = (int)$component['w'] + $pad * 2;
                $bh = (int)$component['h'] + $pad * 2;

                $digitFile = $cropBase . '-digit-' . ($idx + 1) . '-p' . $pad . '-' . $mode . '.png';
                $filters = $mode === 'threshold45'
                    ? ' -resize 400% -colorspace Gray -auto-level -threshold 45% '
                    : ' -resize 400% ';
                $dCmd = escapeshellarg($convert) . ' ' . escapeshellarg($baseCrop)
                    . ' -crop ' . escapeshellarg($bw . 'x' . $bh . '+' . $bx . '+' . $by)
                    . ' +repage -bordercolor white -border 3 '
                    . $filters . ' -strip ' . escapeshellarg($digitFile);
                runCommand($dCmd, $cropBase . '-digit-' . ($idx + 1) . '-p' . $pad . '-' . $mode . '-prep.txt');
                if (!is_file($digitFile)) continue;

                $ocr = runOCR(
                    $config, $digitFile, 'fas', 13,
                    $cropBase . '-digit-' . ($idx + 1) . '-p' . $pad . '-' . $mode . '-ocr.txt',
                    null
                );
                $raw = trim((string)$ocr['stdout']);
                $trialRaw[] = $raw;
                $ascii = faDigitsToEn($raw);
                $mDigit = [];
                preg_match_all('/[0-9]/', $ascii, $mDigit);
                $found = (array)($mDigit[0] ?? []);
                /* Ignore multi-digit hallucinations inside a single component. */
                if (count($found) === 1) $votes[] = (string)$found[0];
            }
        }

        if (!$votes) return null;
        $freq = array_count_values($votes);
        arsort($freq);
        $digit = (string)array_key_first($freq);

        /* Specific ۲↔۳ rescue. Tests on the supplied card font show that a
           genuine ۳ remains ۳, while genuine ۲ often alternates between ۲ and
           ۳ depending on crop padding. This has only been validated for the
           model-year field font; applying it globally corrupted unrelated
           digits (e.g. plate blocks) whenever a single stray '3' misread
           appeared alongside a correct '2' elsewhere in the same field.
           Scope it to 'year' only, and only when ۲ is not clearly outvoted. */
        if ($kind === 'year' && isset($freq['2']) && isset($freq['3']) && $freq['2'] >= $freq['3']) {
            $digit = '2';
        }

        $digits[] = $digit;
        $rawParts[] = implode(' / ', array_filter($trialRaw, static function($v): bool { return $v !== ''; }));
    }

    if (count($digits) !== $expectedCount) return null;
    $value = implode('', $digits);
    if (!ctype_digit($value)) return null;

    $bonus = 190;
    if ($kind === 'year' && strlen($value) === 4 && (int)$value >= 1900 && (int)$value <= 2100) $bonus += 110;
    if ($kind === 'national_id') $bonus += 80;

    return [
        'source'=>'component-digit-rescue', 'variant'=>'multi-pad-digit2-rescue', 'psm'=>13,
        'lang'=>'fas', 'rotation'=>0,
        'raw'=>implode(' || ', $rawParts), 'normalized'=>$value,
        'base_score'=>scoreAutoValue($kind, $value) + $bonus,
        'consensus'=>1, 'score'=>scoreAutoValue($kind, $value) + $bonus,
        'stderr'=>'',
    ];
}

function inferDigitRescueCount(string $kind, array $field, array $candidates): int
{
    if (isset($field['digit_count'])) return (int)$field['digit_count'];
    if ($kind === 'year') return 4;
    if ($kind === 'plate_two' || $kind === 'plate_iran') return 2;
    if ($kind === 'plate_three') return 3;
    if ($kind === 'national_id') {
        $freq = [];
        foreach ($candidates as $candidate) {
            $v = (string)($candidate['normalized'] ?? '');
            if ($v !== '' && ctype_digit($v)) {
                $len = strlen($v);
                if ($len >= 4 && $len <= 12) $freq[$len] = ($freq[$len] ?? 0) + 1;
            }
        }
        if ($freq) {
            arsort($freq);
            return (int)array_key_first($freq);
        }
    }
    return 0;
}

function normalizeAutoValue(string $kind, string $text, array $field): string
{
    $remove = (array)($field['remove'] ?? []);
    $text = trim($text);

    if ($kind === 'vin') {
        $v = strtoupper($text);
        return preg_replace('/[^A-Z0-9]/', '', $v) ?? '';
    }
    if ($kind === 'engine') {
        $v = strtoupper($text);
        return preg_replace('/[^A-Z0-9]/', '', $v) ?? '';
    }
    if ($kind === 'latin_word') {
        $v = strtoupper($text);
        $v = preg_replace('/[^A-Z0-9\- ]/', ' ', $v) ?? '';
        $v = preg_replace('/\s+/', ' ', $v) ?? '';
        $parts = preg_split('/\s+/', trim($v)) ?: [];
        usort($parts, function (string $a, string $b): int {
            $sa = preg_match_all('/[A-Z]/', $a) * 10 + strlen($a);
            $sb = preg_match_all('/[A-Z]/', $b) * 10 + strlen($b);
            return $sa === $sb ? 0 : ($sa > $sb ? -1 : 1);
        });
        return trim((string)($parts[0] ?? ''), " -\t\n\r\0\x0B");
    }
    if ($kind === 'year') return repairYearDigitTwo($text);
    if ($kind === 'serial') return bestDigitSequence($text, [11]);
    if ($kind === 'barcode') return bestDigitSequence($text, [9]);
    if ($kind === 'national_id') return bestDigitSequence($text, [10,11]);
    if ($kind === 'plate_two') return bestDigitSequence($text, [2]);
    if ($kind === 'plate_three') return bestDigitSequence($text, [3]);
    if ($kind === 'plate_iran') return bestDigitSequence($text, [2]);
    if ($kind === 'plate_letter') {
        $v = normalizeFaText($text);
        foreach ($remove as $label) $v = str_replace(normalizeFaText((string)$label), '', $v);
        $m = [];
        preg_match_all('/[ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی]/u', $v, $m);
        return (string)(($m[0] ?? [])[0] ?? '');
    }
    if ($kind === 'fuel') {
        return canonicalFuelValue($text);
    }
    if ($kind === 'usage') {
        return canonicalUsageValue($text);
    }
    if ($kind === 'system') {
        return canonicalSystemValue($text);
    }
    if ($kind === 'room') {
        $v = normalizeFaText($text);
        if (preg_match('/[-–—ـ]/u', $v)) return '-';
        $v = preg_replace('/[^A-Za-z0-9\x{0600}-\x{06FF}]/u', '', $v) ?? '';
        return trim($v);
    }

    $value = bestPersianValue($text, $remove, $kind);
    if ($kind === 'owner_name') {
        // Remove punctuation/noise at both ends without changing the actual name.
        $value = preg_replace('/^[^\x{0600}-\x{06FF}]+|[^\x{0600}-\x{06FF}]+$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
    if ($kind === 'color') {
        $value = preg_replace('/\s*[-–—]\s*/u', '-', $value) ?? $value;
    }
    if ($kind === 'usage') {
        $value = preg_replace('/\s*[-–—]\s*/u', '-', $value) ?? $value;
    }
    return trim($value);
}

function scoreAutoValue(string $kind, string $value): int
{
    if ($value === '') return -1000;

    if ($kind === 'vin') return candidateScore('vin', $value) + 30;
    if ($kind === 'engine') return candidateScore('engine', $value) + 20;
    if ($kind === 'latin_word') return candidateScore('model', $value);

    if ($kind === 'year') {
        $score = strlen($value) * 10;
        if (strlen($value) === 4) $score += 120;
        $n = (int)$value;
        if ($n >= 1900 && $n <= 2100) $score += 190;
        elseif ($n >= 1300 && $n <= 1500) $score += 70;
        return $score;
    }

    if ($kind === 'serial') {
        $len = strlen($value); $score = $len * 8;
        if ($len === 11) $score += 140;
        elseif ($len >= 8 && $len <= 15) $score += 40;
        return $score;
    }
    if ($kind === 'barcode') {
        $len = strlen($value); $score = $len * 8;
        if ($len === 9) $score += 120;
        elseif ($len >= 6 && $len <= 15) $score += 35;
        return $score;
    }
    if ($kind === 'national_id') {
        $len = strlen($value); $score = $len * 7;
        if ($len === 10 || $len === 11) $score += 140;
        elseif ($len >= 4 && $len <= 12) $score += 45;
        return $score;
    }
    if ($kind === 'plate_two') return strlen($value) === 2 ? 220 : 30 - abs(2 - strlen($value)) * 40;
    if ($kind === 'plate_three') return strlen($value) === 3 ? 230 : 30 - abs(3 - strlen($value)) * 40;
    if ($kind === 'plate_iran') return strlen($value) === 2 ? 220 : 30 - abs(2 - strlen($value)) * 40;
    if ($kind === 'plate_letter') return mb_strlen($value, 'UTF-8') === 1 ? 240 : -200;
    if ($kind === 'room') return $value === '-' ? 160 : mb_strlen($value, 'UTF-8') * 6;

    $pc = persianCharCount($value);
    $score = $pc * 7 + mb_strlen($value, 'UTF-8') * 2;
    if ($kind === 'usage' && mb_strpos($value, 'سواری') !== false) $score += 180;
    if ($kind === 'usage' && in_array($value, ['سواری-سواری','سواری-کار'], true)) $score += 120;
    if ($kind === 'capacity' && mb_strpos($value, 'نفر') !== false) $score += 120;
    if ($kind === 'fuel' && preg_match('/بنزین|گازوئیل|گازوییل|هیبرید|برقی|دوگانه|CNG|LPG/u', $value)) $score += 220;
    if ($kind === 'color' && mb_strpos($value, 'متالیک') !== false) $score += 120;
    if ($kind === 'owner_name' && $pc >= 4) $score += 80;
    if ($kind === 'system' && $pc >= 3) {
        $score += 90;
        $len = mb_strlen($value, 'UTF-8');
        if ($len > 8) $score -= ($len - 8) * 18;
        if (substr_count($value, ' ') > 1) $score -= 35;
    }
    return $score;
}

function validateAutoValue(string $kind, string $value): array
{
    if ($kind === 'vin') return fieldValidation('vin', $value);
    if ($kind === 'engine') return fieldValidation('engine', $value);
    if ($kind === 'latin_word') return fieldValidation('model', $value);
    if ($kind === 'year') {
        $n = (int)$value;
        $valid = strlen($value) === 4 && (($n >= 1300 && $n <= 1500) || ($n >= 1900 && $n <= 2100));
        return ['valid'=>$valid, 'message'=>$valid ? 'سال مدل معتبر است.' : 'سال مدل مشکوک است.'];
    }
    if ($kind === 'plate_two') return ['valid'=>strlen($value) === 2, 'message'=>strlen($value) === 2 ? 'دو رقم پلاک معتبر است.' : 'دو رقم پلاک کامل تشخیص داده نشد.'];
    if ($kind === 'plate_three') return ['valid'=>strlen($value) === 3, 'message'=>strlen($value) === 3 ? 'سه رقم پلاک معتبر است.' : 'سه رقم پلاک کامل تشخیص داده نشد.'];
    if ($kind === 'plate_iran') return ['valid'=>strlen($value) === 2, 'message'=>strlen($value) === 2 ? 'کد ایران معتبر است.' : 'کد ایران کامل تشخیص داده نشد.'];
    if ($kind === 'plate_letter') return ['valid'=>mb_strlen($value, 'UTF-8') === 1, 'message'=>mb_strlen($value, 'UTF-8') === 1 ? 'حرف پلاک تشخیص داده شد.' : 'حرف پلاک معتبر تشخیص داده نشد.'];
    if (in_array($kind, ['serial','barcode','national_id'], true)) {
        $valid = $value !== '' && ctype_digit($value);
        return ['valid'=>$valid, 'message'=>$valid ? 'مقدار عددی استخراج شد.' : 'مقدار عددی معتبر نیست.'];
    }
    return ['valid'=>$value !== '', 'message'=>$value !== '' ? 'مقدار استخراج شد.' : 'مقدار قابل اعتماد استخراج نشد.'];
}

function detectCardTemplateDetailed(array $templates, array $wholeResults): array
{
    $text = normalizeFaText(wholeTextCombined($wholeResults));
    $scores = ['vehicle_card'=>0, 'ownership_card'=>0];
    $hits = ['vehicle_card'=>[], 'ownership_card'=>[]];

    $weighted = [
        'vehicle_card' => [
            'شماره موتور'=>65, 'نوع سوخت'=>55, 'کاربری'=>50, 'شماره اتاق'=>45,
            'سیستم'=>30, 'تیپ'=>30, 'ظرفیت'=>25, 'شماره شاسی'=>20,
        ],
        'ownership_card' => [
            'نام مالک'=>80, 'شماره پلاک'=>75, 'شناسه ملی'=>65, 'شماره ملی'=>55,
            'کارت شناسایی'=>55, 'مالک'=>30, 'VIN'=>10,
        ],
    ];

    foreach ($weighted as $key => $rules) {
        foreach ($rules as $needle => $weight) {
            if (mb_strpos($text, normalizeFaText((string)$needle)) !== false) {
                $scores[$key] += (int)$weight;
                $hits[$key][] = $needle;
            }
        }
    }

    arsort($scores);
    $keys = array_keys($scores);
    $best = (string)($keys[0] ?? '');
    $bestScore = (int)($scores[$best] ?? 0);
    $secondScore = (int)($scores[(string)($keys[1] ?? '')] ?? 0);

    // We only support these two templates for now. If text is weak, still select the higher score,
    // but mark confidence low so the UI can say the result should be reviewed.
    $confidence = 'low';
    if ($bestScore >= 100 && ($bestScore - $secondScore) >= 40) $confidence = 'high';
    elseif ($bestScore >= 45 && ($bestScore - $secondScore) >= 15) $confidence = 'medium';

    return [
        'template'=>$best,
        'score'=>$bestScore,
        'second_score'=>$secondScore,
        'confidence'=>$confidence,
        'scores'=>$scores,
        'hits'=>$hits,
    ];
}

function detectCardTemplate(array $templates, array $wholeResults): string
{
    $d = detectCardTemplateDetailed($templates, $wholeResults);
    return (string)($d['template'] ?? '');
}

function templatePixelRect(array $roi, int $width, int $height): array
{
    $x = max(0, (int)round((float)$roi[0] * $width));
    $y = max(0, (int)round((float)$roi[1] * $height));
    $w = max(8, (int)round((float)$roi[2] * $width));
    $h = max(8, (int)round((float)$roi[3] * $height));
    if ($x + $w > $width) $w = max(1, $width - $x);
    if ($y + $h > $height) $h = max(1, $height - $y);
    return ['x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h];
}

function wholeFallbackCandidates(string $fieldKey, string $kind, array $wholeResults, array $field): array
{
    $text = wholeTextCombined($wholeResults);
    $norm = normalizeFaText($text);
    $values = [];

    if ($kind === 'vin') {
        $m = [];
        preg_match_all('/\b[A-HJ-NPR-Z0-9]{17}\b/i', strtoupper($text), $m);
        $values = $m[0] ?? [];
    } elseif ($kind === 'engine') {
        $m = [];
        preg_match_all('/\b[A-Z]{2,5}[0-9]{5,10}\b/i', strtoupper($text), $m);
        $values = $m[0] ?? [];
    } elseif ($kind === 'year') {
        $m = [];
        /* First prefer the value immediately after the printed مدل label. */
        preg_match_all('/مدل\s*[:：]?\s*([0-9۰-۹\s]{3,10})/u', $text, $m);
        foreach ((array)($m[1] ?? []) as $v) {
            $d = bestDigitSequence((string)$v, [4]);
            if ($d !== '') $values[] = $d;
        }
        /* Also keep any standalone 3/4-digit OCR candidate; repairYearDigitTwo
           and the visual geometry rescue decide whether it is trustworthy. */
        $m2 = [];
        preg_match_all('/(?<![0-9])[0-9۰-۹]{3,4}(?![0-9۰-۹])/', $text, $m2);
        foreach ((array)($m2[0] ?? []) as $v) $values[] = (string)$v;
    } elseif ($kind === 'system') {
        $m = [];
        preg_match_all('/سیستم\s*[:：]?\s*([^\r\n]{2,35})/u', $norm, $m);
        $values = $m[1] ?? [];
    } elseif ($kind === 'usage') {
        $m = [];
        preg_match_all('/کاربری\s*[:：]?\s*([^\r\n]{2,45})/u', $norm, $m);
        $values = $m[1] ?? [];
    } elseif ($kind === 'latin_word') {
        $m = [];
        preg_match_all('/تیپ\s*[:：]?\s*([A-Z][A-Z0-9-]{3,20})/iu', $text, $m);
        $values = $m[1] ?? [];
        if (!$values) {
            $m2 = [];
            preg_match_all('/\b[A-Z][A-Z0-9\-]{3,15}\b/i', strtoupper($text), $m2);
            foreach ($m2[0] ?? [] as $v) {
                $u = strtoupper((string)$v);
                if (strlen(preg_replace('/[^A-Z]/', '', $u) ?? '') >= 4 && strlen($u) < 17) $values[] = $u;
            }
        }
    } elseif ($kind === 'serial') {
        $m = [];
        preg_match_all('/(?<![0-9])[0-9]{10,12}(?![0-9])/', faDigitsToEn($norm), $m);
        $values = $m[0] ?? [];
    } elseif ($kind === 'barcode') {
        $m = [];
        preg_match_all('/(?<![0-9])[0-9]{8,10}(?![0-9])/', faDigitsToEn($norm), $m);
        $values = $m[0] ?? [];
    } elseif ($kind === 'owner_name') {
        $m = [];
        preg_match_all('/نام\s*مالک\s*[:：]?\s*([^\n\r]{3,60})/u', $norm, $m);
        foreach ((array)($m[1] ?? []) as $v) {
            $v = preg_replace('/^[^\x{0600}-\x{06FF}]+/u', '', (string)$v) ?? (string)$v;
            $v = preg_replace('/^(?:[\x{0600}-\x{06FF}]{1,2})\s+(?=[\x{0600}-\x{06FF}]{3,})/u', '', trim($v)) ?? trim($v);
            if (persianCharCount($v) >= 3) $values[] = trim($v);
        }
    } elseif ($kind === 'national_id') {
        $m = [];
        preg_match_all('/(?:شماره\s*ملی|شناسه\s*ملی|شماره\s*شناسه\s*ملی)[^\n\r0-9۰-۹]{0,20}[\s\n\r:：-]*([0-9۰-۹]{4,12})/u', $text, $m);
        $values = $m[1] ?? [];
    }

    $out = [];
    foreach (array_unique($values) as $v) {
        $normalized = normalizeAutoValue($kind, (string)$v, $field);
        if ($normalized === '') continue;
        $fallbackBonus = 25;
        // Label-neighbor values are deliberately rewarded; they are much less likely
        // to contain watermark fragments than a wide fixed ROI.
        if ($kind === 'owner_name') $fallbackBonus = 120;
        elseif ($kind === 'system') $fallbackBonus = 190;
        elseif ($kind === 'year') $fallbackBonus = 180;
        elseif ($kind === 'latin_word') $fallbackBonus = 130;
        elseif ($kind === 'usage') $fallbackBonus = 100;

        $out[] = [
            'source'=>'whole-card', 'variant'=>'whole-card', 'psm'=>'mixed', 'rotation'=>0,
            'raw'=>(string)$v, 'normalized'=>$normalized,
            'base_score'=>scoreAutoValue($kind, $normalized) + $fallbackBonus,
            'consensus'=>0, 'score'=>0, 'stderr'=>'',
        ];
    }
    return $out;
}


function embeddedPersianDigit2TemplateBase64(): string
{
    /* 48x64 binary glyph template built from the same official card numeral font.
       It is embedded so deployment stays a single PHP file. */
    return 'iVBORw0KGgoAAAANSUhEUgAAADAAAABACAAAAAB5S6uaAAABIklEQVRIDZXBAXKjQBAEwar/P7ovdANoYdt2kCkvyUvykrwki4A0ARmyCEgTkCFf4UM2AWTIV/iQTQAZcgoXWYUPGXIKC7mE/2TIKSzkEv6TISM8GVYyZIQ/yJAR/iBDRvidHGSE38lBRvidHOQUfiYXOYUfyZecwo/kS75CJSv5CpWsZBE28iCLsJJGFmEljdyEi1RyEy5SyU24SCV34SSV3IWLNHIXLtLIXbhII0/hII08hYM08hQO0shTOEgjT+EgjWzCkEY2YUgjmzCkkU0Y0sgmDGmkCEN2UoQhOynCkJ0UYchOijBkJ0UYspMiDNlJEYbspAgf0kgVQBqpAkgjVQBppAogjVQBpJEqgDRSBaSSKiCVVAGp5CV5SV6Sl/4B6c9AQU5hfIIAAAAASUVORK5CYII=';
}

function ensurePersianDigit2Template(string $jobDir): string
{
    $file = $jobDir . '/persian-digit2-template-v10.png';
    if (!is_file($file)) {
        $raw = base64_decode(embeddedPersianDigit2TemplateBase64(), true);
        if ($raw !== false) @file_put_contents($file, $raw);
    }
    return is_file($file) ? $file : '';
}

function embeddedPersianDigit3TemplateBase64(): string
{
    /* Normalized Persian ۳ glyph from the same official vehicle-card numeral style.
       Used only as a shape reference, never as a hard-coded OCR value. */
    return 'iVBORw0KGgoAAAANSUhEUgAAADAAAABAAQAAAAB0W8nrAAAAAW9yTlQBz6J3mgAAAHFJREFUGNONzbENgCAUhOEjFJRsoJvoSk4gJg7nGrgBiQ2F4fngJUYNiVzzFVf8QMM8OkxQAQOjA2bGRCbAJDg+LClaM5q2DO+N/2AyC7Sgbg7iHtGeCqXukhCfjEL/wlY5Cyb9ogXVDKpID7GZUKNxF9aGZbWdR2OTAAAAAElFTkSuQmCC';
}

function ensurePersianDigit3Template(string $jobDir): string
{
    $file = $jobDir . '/persian-digit3-template-v11.png';
    if (!is_file($file)) {
        $raw = base64_decode(embeddedPersianDigit3TemplateBase64(), true);
        if ($raw !== false) @file_put_contents($file, $raw);
    }
    return is_file($file) ? $file : '';
}

function darkDigitComponents(string $convert, string $baseCrop, string $cropBase, int $expectedCount): array
{
    /* Try more than one threshold. Security-print backgrounds vary a lot between
       cards, and one fixed threshold can either merge or fragment Persian digits. */
    foreach ([52, 56, 60] as $threshold) {
        $binary = $cropBase . '-visual-components-t' . $threshold . '.png';
        $cmd = escapeshellarg($convert) . ' ' . escapeshellarg($baseCrop)
            . ' -colorspace Gray -auto-level -threshold ' . (int)$threshold . '% -strip ' . escapeshellarg($binary);
        runCommand($cmd, $cropBase . '-visual-components-t' . $threshold . '-prep.txt');
        if (!is_file($binary)) continue;

        $cc = runCommand(
            escapeshellarg($convert) . ' ' . escapeshellarg($binary)
            . ' -define connected-components:verbose=true -connected-components 8 null:',
            $cropBase . '-visual-components-t' . $threshold . '-cc.txt'
        );
        $verbose = trim((string)$cc['stdout'] . "\n" . (string)$cc['stderr']);
        if ($verbose === '') continue;

        $components = [];
        foreach (preg_split('/\R/u', $verbose) ?: [] as $line) {
            $m = [];
            /* IM6/IM7 may report gray(0), gray(0.0) or srgb(0,0,0). */
            if (!preg_match('/^\s*\d+:\s+(\d+)x(\d+)\+(\d+)\+(\d+).*?\s+(\d+)\s+(?:gray\(0(?:\.0+)?\)|s?rgb\(0(?:\.0+)?,\s*0(?:\.0+)?,\s*0(?:\.0+)?\))\s*$/i', trim((string)$line), $m)) continue;
            $w=(int)$m[1]; $h=(int)$m[2]; $x=(int)$m[3]; $y=(int)$m[4]; $area=(int)$m[5];
            if ($w < 3 || $h < 5 || $area < 8) continue;
            $components[]=['x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'area'=>$area,'threshold'=>$threshold];
        }
        if (count($components) < $expectedCount) continue;
        usort($components, static fn(array $a,array $b): int => (int)$b['area'] <=> (int)$a['area']);
        $components=array_slice($components,0,$expectedCount);
        usort($components, static fn(array $a,array $b): int => (int)$a['x'] <=> (int)$b['x']);
        if (count($components)===$expectedCount) return $components;
    }
    return [];
}

function normalizeDigitComponentImage(
    string $convert,
    string $baseCrop,
    string $cropBase,
    array $component,
    int $index
): string {
    $candidate = $cropBase . '-visual-glyph-' . $index . '.png';
    $geom = (int)$component['w'].'x'.(int)$component['h'].'+'.(int)$component['x'].'+'.(int)$component['y'];
    $threshold = (int)($component['threshold'] ?? 56);
    $cmd = escapeshellarg($convert) . ' ' . escapeshellarg($baseCrop)
        . ' -crop ' . escapeshellarg($geom) . ' +repage'
        . ' -colorspace Gray -auto-level -threshold ' . $threshold . '% -negate -trim +repage'
        . ' -resize ' . escapeshellarg('40x56')
        . ' -gravity center -background black -extent 48x64 -threshold 50% -strip '
        . escapeshellarg($candidate);
    runCommand($cmd, $cropBase . '-visual-glyph-' . $index . '-prep.txt');
    return is_file($candidate) ? $candidate : '';
}

function imageDifferenceMean(string $convert, string $templateFile, string $candidateFile, string $errorFile): ?float
{
    if ($templateFile === '' || $candidateFile === '' || !is_file($templateFile) || !is_file($candidateFile)) return null;
    /* Uses convert itself, which is already a verified project dependency. This
       avoids v0.10 silently losing the rescue when the separate `compare` binary
       is unavailable in a jailed/shared-host PATH. */
    $cmd = escapeshellarg($convert) . ' ' . escapeshellarg($templateFile) . ' ' . escapeshellarg($candidateFile)
        . ' -compose difference -composite -colorspace Gray -format ' . escapeshellarg('%[fx:mean]') . ' info:';
    $r = runCommand($cmd, $errorFile);
    $txt = trim((string)$r['stdout']);
    if ($txt === '' || !is_numeric($txt)) return null;
    $v = (float)$txt;
    return ($v >= 0.0 && $v <= 1.0) ? $v : null;
}

function componentPersianDigit2Confidence(
    string $convert,
    string $baseCrop,
    string $cropBase,
    array $component,
    int $index,
    string $digit2Template,
    string $digit3Template
): array {
    $candidate = normalizeDigitComponentImage($convert,$baseCrop,$cropBase,$component,$index);
    if ($candidate === '') return ['is2'=>false,'d2'=>null,'d3'=>null,'confidence'=>0.0];
    $d2 = imageDifferenceMean($convert,$digit2Template,$candidate,$cropBase.'-visual-d2-'.$index.'-diff.txt');
    $d3 = imageDifferenceMean($convert,$digit3Template,$candidate,$cropBase.'-visual-d3-'.$index.'-diff.txt');
    if ($d2 === null) return ['is2'=>false,'d2'=>$d2,'d3'=>$d3,'confidence'=>0.0];

    /* On the supplied official cards, genuine ۲ glyphs normalize around 0.03-0.10
       from the ۲ template, while ۷/۴/۶ are far above 0.19. A real ۳ is closer to
       the ۳ template. Requiring both absolute closeness and a margin over ۳ keeps
       the rescue conservative for unseen cards. */
    $margin = $d3 !== null ? ($d3 - $d2) : 0.0;
    $is2 = $d2 <= 0.18 && ($d3 === null || $margin >= 0.015);
    $confidence = $is2 ? max(0.0, min(1.0, (0.18-$d2)/0.18 + max(0.0,$margin))) : 0.0;
    return ['is2'=>$is2,'d2'=>$d2,'d3'=>$d3,'confidence'=>$confidence];
}

function bestBaseDigitSequenceFromCandidates(array $candidates, int $expectedCount): string
{
    $freq=[];
    foreach ($candidates as $c) {
        foreach ([(string)($c['raw'] ?? ''),(string)($c['normalized'] ?? '')] as $raw) {
            $d=preg_replace('/[^0-9]/','',faDigitsToEn($raw)) ?? '';
            $len=strlen($d);
            if ($len === $expectedCount || $len === $expectedCount-1) {
                $weight = $len === $expectedCount ? 3 : 1;
                $freq[$d]=($freq[$d] ?? 0)+$weight;
            }
        }
    }
    if (!$freq) return '';
    uksort($freq, function(string $a,string $b) use ($freq,$expectedCount): int {
        $sa=(int)$freq[$a]+(strlen($a)===$expectedCount?100:0);
        $sb=(int)$freq[$b]+(strlen($b)===$expectedCount?100:0);
        return $sa===$sb ? 0 : ($sa>$sb ? -1 : 1);
    });
    return (string)array_key_first($freq);
}

function visualDigitStructureRescueCandidate(
    array $config,
    string $convert,
    string $baseCrop,
    string $cropBase,
    int $expectedCount,
    string $kind,
    array $candidates
): ?array {
    $supported=['national_id','year','plate_two','plate_three','plate_iran'];
    if (!in_array($kind,$supported,true) || !in_array($expectedCount,[2,3,4],true)) return null;

    $components=darkDigitComponents($convert,$baseCrop,$cropBase,$expectedCount);
    if (count($components)!==$expectedCount) return null;
    $base=bestBaseDigitSequenceFromCandidates($candidates,$expectedCount);
    if ($base==='') return null;

    $sequences=[];
    if (strlen($base)===$expectedCount) {
        $sequences[]=$base;
    } elseif ($kind==='year' && strlen($base)===$expectedCount-1) {
        $maxH=max(array_map(static fn(array $c): int => (int)$c['h'],$components));
        $maxW=max(array_map(static fn(array $c): int => (int)$c['w'],$components));
        foreach ($components as $idx=>$c) {
            if ((int)$c['h'] >= (int)round($maxH*0.72) && (int)$c['w'] <= (int)round($maxW*0.58)) {
                $sequences[]=substr($base,0,$idx).'1'.substr($base,$idx);
            }
        }
    }
    if (!$sequences) return null;

    $digit2Template=ensurePersianDigit2Template(dirname($cropBase));
    $digit3Template=ensurePersianDigit3Template(dirname($cropBase));
    if ($digit2Template==='' || $digit3Template==='') return null;

    $shape=[];
    foreach ($components as $idx=>$component) {
        $shape[$idx]=componentPersianDigit2Confidence(
            $convert,$baseCrop,$cropBase,$component,$idx,$digit2Template,$digit3Template
        );
    }

    $best=''; $bestScore=-PHP_INT_MAX; $bestRaw=''; $changedCount=0;
    foreach (array_unique($sequences) as $seq) {
        if (strlen($seq)!==$expectedCount) continue;
        $chars=str_split($seq); $changed=0;
        foreach ($chars as $idx=>$ch) {
            /* The recurring failure mode is OCR ۲ as ۳. Only rewrite a textual 3
               when the corresponding glyph is independently proven to look like ۲. */
            if ($ch==='3' && !empty($shape[$idx]['is2'])) {
                $chars[$idx]='2'; $changed++;
            }
        }
        $value=implode('',$chars);
        if (!ctype_digit($value)) continue;
        $score=scoreAutoValue($kind,$value)+520 + ($changed*80);
        if ($kind==='year') {
            $n=(int)$value;
            if ($n>=1900 && $n<=2100) $score+=220;
            else $score-=280;
        }
        if ($score>$bestScore) {
            $bestScore=$score; $best=$value; $changedCount=$changed;
            $bestRaw='base='.$base.'; changed='.$changed.'; shape='.json_encode($shape,JSON_UNESCAPED_SLASHES);
        }
    }
    if ($best==='' || $changedCount===0) return null;
    return [
        'source'=>'visual-digit-structure-rescue-v11','variant'=>'convert-difference-2vs3','psm'=>'visual',
        'lang'=>'image','rotation'=>0,'raw'=>$bestRaw,'normalized'=>$best,
        'base_score'=>$bestScore,'consensus'=>1,'score'=>$bestScore,'stderr'=>'',
    ];
}

function addBarcodeLeadingOneRescue(array $candidates, array $field): array
{
    if (empty($field['leading_one_rescue'])) return $candidates;
    $extra=[];
    foreach ($candidates as $c) {
        $v=(string)($c['normalized'] ?? '');
        if (strlen($v)===8 && ctype_digit($v) && $v[0]==='9') {
            $repaired='1'.$v;
            $extra[]=[
                'source'=>'barcode-leading-one-rescue','variant'=>'narrow-leading-1','psm'=>'heuristic','lang'=>'eng','rotation'=>0,
                'raw'=>$v,'normalized'=>$repaired,
                'base_score'=>scoreAutoValue('barcode',$repaired)+180,'consensus'=>1,'score'=>0,'stderr'=>'',
            ];
        }
    }
    return array_merge($candidates,$extra);
}

function extractTemplateField(
    array $config,
    string $convert,
    string $rectifiedFile,
    string $jobDir,
    string $fieldKey,
    array $field,
    array $wholeResults,
    int &$runCounter
): array {
    $size = getimagesize($rectifiedFile);
    if (!$size) throw new RuntimeException('ابعاد کارت برای استخراج ساختاریافته قابل خواندن نیست.');
    $iw = (int)$size[0]; $ih = (int)$size[1];
    $rect = templatePixelRect((array)$field['roi'], $iw, $ih);
    $kind = (string)$field['kind'];
    $profile = autoProfile($kind);
    $rotations = (array)($field['rotations'] ?? [0]);
    $candidates = [];

    $cropBase = $jobDir . '/auto-' . preg_replace('/[^a-z0-9_\-]/i', '_', $fieldKey);
    $baseCrop = $cropBase . '-crop.png';
    $cropCmd = escapeshellarg($convert) . ' ' . escapeshellarg($rectifiedFile)
        . ' -crop ' . escapeshellarg($rect['w'].'x'.$rect['h'].'+'.$rect['x'].'+'.$rect['y'])
        . ' +repage -strip ' . escapeshellarg($baseCrop);
    $cropRun = runCommand($cropCmd, $cropBase . '-crop-error.txt');

    if (!is_file($baseCrop)) {
        return [
            'key'=>$fieldKey, 'label'=>(string)$field['label'], 'kind'=>$kind, 'rect'=>$rect,
            'best'=>['normalized'=>'','score'=>-1000,'source'=>'crop','consensus'=>0],
            'validation'=>['valid'=>false,'message'=>'Crop این فیلد ساخته نشد.'],
            'candidates'=>[['source'=>'crop','raw'=>'','normalized'=>'','score'=>-1000,'stderr'=>$cropRun['stderr']]],
        ];
    }

    foreach ($rotations as $rotation) {
        $rotation = (int)$rotation;
        $workingCrop = $baseCrop;
        if ($rotation !== 0) {
            $workingCrop = $cropBase . '-r' . $rotation . '.png';
            $rCmd = escapeshellarg($convert) . ' ' . escapeshellarg($baseCrop)
                . ' -rotate ' . $rotation . ' +repage -strip ' . escapeshellarg($workingCrop);
            runCommand($rCmd, $cropBase . '-r' . $rotation . '-error.txt');
            if (!is_file($workingCrop)) continue;
        }

        foreach ((array)$profile['variants'] as $variant) {
            $processed = $cropBase . '-r' . $rotation . '-' . $variant . '.png';
            $prep = preprocessImage(
                $config, $convert, $workingCrop, $processed, (string)$variant,
                $cropBase . '-r' . $rotation . '-' . $variant . '-prep.txt'
            );
            if (!is_file($processed)) continue;

            foreach ((array)$profile['langs'] as $lang) {
                foreach ((array)$profile['psms'] as $psm) {
                    if ($runCounter >= (int)$config['max_auto_template_runs']) break 4;
                    $runCounter++;
                    $ocr = runOCR(
                        $config, $processed, (string)$lang, (int)$psm,
                        $cropBase . '-r' . $rotation . '-' . $variant . '-' . $lang . '-psm' . (int)$psm . '.txt',
                        $profile['whitelist'] !== null ? (string)$profile['whitelist'] : null
                    );
                    $normalized = normalizeAutoValue($kind, (string)$ocr['stdout'], $field);
                    $baseScore = scoreAutoValue($kind, $normalized);
                    $candidates[] = [
                        'source'=>'template-roi', 'variant'=>(string)$variant, 'psm'=>(int)$psm,
                        'lang'=>(string)$lang, 'rotation'=>$rotation,
                        'raw'=>(string)$ocr['stdout'], 'normalized'=>$normalized,
                        'base_score'=>$baseScore, 'consensus'=>0, 'score'=>$baseScore,
                        'stderr'=>(string)$ocr['stderr'],
                    ];
                }
            }
        }
    }

    if (!empty($field['digit_rescue'])) {
        $digitCount = inferDigitRescueCount($kind, $field, $candidates);
        if ($digitCount > 0) {
            /* v0.12 regression policy:
               - national ID / year still need the image-shape ۲↔۳ rescue.
               - plate numeric blocks go back to the stable per-component OCR used in v0.10.
                 Generic visual rescue must NOT rewrite plate prefix / Iran code, because a
                 valid 85 / 38 was observed being corrupted to 18 / 32 on another real card.
               - the only plate shape correction is a separate, conservative post-pass for
                 a leading 3 in the three-digit block (e.g. OCR 384 when the glyph is ۲). */
            if (in_array($kind, ['national_id','year'], true)) {
                $visualCandidate = visualDigitStructureRescueCandidate(
                    $config, $convert, $baseCrop, $cropBase, $digitCount, $kind, $candidates
                );
                if (is_array($visualCandidate)) $candidates[] = $visualCandidate;
            } else {
                $digitCandidate = digitComponentRescueCandidate(
                    $config, $convert, $baseCrop, $cropBase, $digitCount, $kind, $runCounter
                );
                if (is_array($digitCandidate)) $candidates[] = $digitCandidate;
            }
        }
    }

    $candidates = array_merge($candidates, wholeFallbackCandidates($fieldKey, $kind, $wholeResults, $field));
    if ($kind === 'barcode') $candidates = addBarcodeLeadingOneRescue($candidates, $field);

    $freq = [];
    foreach ($candidates as $c) {
        $v = (string)($c['normalized'] ?? '');
        if ($v !== '') $freq[$v] = ($freq[$v] ?? 0) + 1;
    }
    foreach ($candidates as &$c) {
        $v = (string)($c['normalized'] ?? '');
        $count = $v !== '' ? (int)($freq[$v] ?? 0) : 0;
        $c['consensus'] = $count;
        $c['score'] = (int)($c['base_score'] ?? -1000) + max(0, $count - 1) * 28;
    }
    unset($c);

    usort($candidates, function (array $a, array $b): int {
        $sa = (int)($a['score'] ?? -1000); $sb = (int)($b['score'] ?? -1000);
        if ($sa === $sb) {
            $ca = (int)($a['consensus'] ?? 0); $cb = (int)($b['consensus'] ?? 0);
            return $ca === $cb ? 0 : ($ca > $cb ? -1 : 1);
        }
        return $sa > $sb ? -1 : 1;
    });

    $best = $candidates[0] ?? ['normalized'=>'','score'=>-1000,'source'=>'none','consensus'=>0,'raw'=>''];
    $validation = validateAutoValue($kind, (string)($best['normalized'] ?? ''));

    return [
        'key'=>$fieldKey,
        'label'=>(string)$field['label'],
        'kind'=>$kind,
        'rect'=>$rect,
        'best'=>$best,
        'validation'=>$validation,
        'candidates'=>array_slice($candidates, 0, 6),
    ];
}

function repairPlateThreeLeadingTwoByShape(
    array $fields,
    array $config,
    string $convert,
    string $rectifiedFile,
    string $jobDir,
    array $template
): array {
    if (!isset($fields['plate_three_digits'], $template['fields']['plate_three_digits'])) return $fields;

    $current = (string)($fields['plate_three_digits']['best']['normalized'] ?? '');

    /* Critical safety rule: this rescue is ONLY for the exact recurring confusion
       Persian ۲ -> OCR ۳ in the first position of the 3-digit plate block.
       It never touches 85, Iran 38, 574, or a later digit. */
    if (strlen($current) !== 3 || $current[0] !== '3') return $fields;

    $size = @getimagesize($rectifiedFile);
    if (!$size) return $fields;
    $rect = templatePixelRect((array)$template['fields']['plate_three_digits']['roi'], (int)$size[0], (int)$size[1]);

    $base = $jobDir . '/plate-three-leading2-check';
    $crop = $base . '-crop.png';
    $cmd = escapeshellarg($convert) . ' ' . escapeshellarg($rectifiedFile)
        . ' -crop ' . escapeshellarg($rect['w'].'x'.$rect['h'].'+'.$rect['x'].'+'.$rect['y'])
        . ' +repage -strip ' . escapeshellarg($crop);
    runCommand($cmd, $base . '-crop-error.txt');
    if (!is_file($crop)) return $fields;

    $components = darkDigitComponents($convert, $crop, $base, 3);
    if (count($components) !== 3) return $fields;

    $d2tpl = ensurePersianDigit2Template($jobDir);
    $d3tpl = ensurePersianDigit3Template($jobDir);
    if ($d2tpl === '' || $d3tpl === '') return $fields;

    $shape = componentPersianDigit2Confidence(
        $convert, $crop, $base, $components[0], 0, $d2tpl, $d3tpl
    );

    $d2 = isset($shape['d2']) && is_numeric($shape['d2']) ? (float)$shape['d2'] : null;
    $d3 = isset($shape['d3']) && is_numeric($shape['d3']) ? (float)$shape['d3'] : null;
    if ($d2 === null) return $fields;
    $margin = $d3 !== null ? ($d3 - $d2) : 0.0;

    /* Stricter than the generic rescue. This avoids converting a real ۳ to ۲.
       The correction happens only when the first glyph is clearly closer to ۲. */
    $strongTwo = !empty($shape['is2']) && $d2 <= 0.135 && ($d3 === null || $margin >= 0.025);
    if (!$strongTwo) return $fields;

    $repaired = '2' . substr($current, 1);
    if (!ctype_digit($repaired) || strlen($repaired) !== 3) return $fields;

    $old = (array)($fields['plate_three_digits']['best'] ?? []);
    $old['normalized'] = $repaired;
    $old['raw'] = 'v0.12 strong-leading-2 shape rescue; previous=' . $current
        . '; d2=' . ($d2 === null ? 'null' : (string)$d2)
        . '; d3=' . ($d3 === null ? 'null' : (string)$d3)
        . '; margin=' . (string)$margin;
    $old['source'] = 'plate-three-leading2-shape-rescue-v12';
    $old['variant'] = 'first-glyph-2-vs-3-only';
    $old['psm'] = 'visual';
    $old['score'] = max((int)($old['score'] ?? 0) + 90, 360);
    $old['consensus'] = max(1, (int)($old['consensus'] ?? 0));

    $fields['plate_three_digits']['best'] = $old;
    $fields['plate_three_digits']['validation'] = validateAutoValue('plate_three', $repaired);
    return $fields;
}

function buildPlateSummary(array $fields): array
{
    $two = (string)($fields['plate_two_digits']['best']['normalized'] ?? '');
    $letter = (string)($fields['plate_letter']['best']['normalized'] ?? '');
    $three = (string)($fields['plate_three_digits']['best']['normalized'] ?? '');
    $iran = (string)($fields['plate_iran_code']['best']['normalized'] ?? '');

    $parts = array_filter([$two, $letter, $three], function ($v): bool { return (string)$v !== ''; });
    $display = implode(' ', $parts);
    if ($iran !== '') $display .= ($display !== '' ? ' — ' : '') . 'ایران ' . $iran;

    $valid = strlen($two) === 2 && mb_strlen($letter, 'UTF-8') === 1 && strlen($three) === 3 && strlen($iran) === 2;
    return [
        'two_digits'=>$two,
        'letter'=>$letter,
        'three_digits'=>$three,
        'iran_code'=>$iran,
        'display'=>$display,
        'valid'=>$valid,
    ];
}

function runStructuredTemplateExtraction(
    array $config,
    string $templateKey,
    array $templates,
    string $rectifiedFile,
    string $jobDir,
    array $wholeResults
): array {
    if (!isset($templates[$templateKey])) throw new RuntimeException('نوع کارت ساختاریافته نامعتبر است.');
    $template = $templates[$templateKey];
    $convert = findConvert();
    $fields = [];
    $runCounter = 0;

    foreach ((array)$template['fields'] as $fieldKey => $field) {
        $fields[$fieldKey] = extractTemplateField(
            $config, $convert, $rectifiedFile, $jobDir, (string)$fieldKey, (array)$field, $wholeResults, $runCounter
        );
    }

    $result = [
        'template'=>$templateKey,
        'template_label'=>(string)$template['label'],
        'ocr_runs'=>$runCounter,
        'fields'=>$fields,
        'created_at'=>date('Y-m-d H:i:s'),
    ];
    if ($templateKey === 'ownership_card') {
        $fields = repairOwnershipPlateFields($fields);
        $fields = repairPlateThreeLeadingTwoByShape(
            $fields, $config, $convert, $rectifiedFile, $jobDir, $template
        );
        $result['fields'] = $fields;
        $result['plate'] = buildPlateSummary($fields);
    }
    return $result;
}


$cardTemplates = cardTemplates();

/*
|--------------------------------------------------------------------------
| v0.7 automatic two-side helpers
|--------------------------------------------------------------------------
*/
function validateUploadImage(array $config, array $upload): array
{
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود تصویر با خطا مواجه شد.');
    }
    if ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > (int)$config['max_file_size']) {
        throw new RuntimeException('حجم تصویر مجاز نیست.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$upload['tmp_name']);
    $allowed = allowedMimes();
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فرمت تصویر پشتیبانی نمی‌شود: ' . $mime);
    }
    $size = @getimagesize((string)$upload['tmp_name']);
    if (!$size) throw new RuntimeException('تصویر قابل خواندن نیست.');
    if (((int)$size[0] * (int)$size[1]) > (int)$config['max_pixels']) {
        throw new RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
    }
    return ['mime'=>$mime, 'ext'=>$allowed[$mime], 'width'=>(int)$size[0], 'height'=>(int)$size[1]];
}

function normalizeCardImage(array $config, string $convert, array $upload, string $jobDir, string $slot): array
{
    $meta = validateUploadImage($config, $upload);
    $slot = preg_replace('/[^a-z0-9_-]/i', '_', $slot) ?: 'card';
    $input = $jobDir . '/' . $slot . '-upload.' . $meta['ext'];
    if (!move_uploaded_file((string)$upload['tmp_name'], $input)) {
        throw new RuntimeException('ذخیره تصویر ' . $slot . ' ناموفق بود.');
    }

    $stage1 = $jobDir . '/' . $slot . '-auto.png';
    /*
     * The user now supplies almost-flat card photos. We therefore remove the
     * manual perspective step and automatically: auto-orient, remove a dark
     * frame when present, deskew small rotation, and resize to a stable size.
     * The black border trick makes -trim remove black screenshot frames without
     * eating the pale green card itself.
     */
    $cmd = escapeshellarg($convert)
        . ' ' . escapeshellarg($input)
        . ' -auto-orient'
        . ' -bordercolor black -border 2 -fuzz 10% -trim +repage'
        . ' -background white -deskew 40% -trim +repage'
        . ' -resize ' . escapeshellarg('1900x1900>')
        . ' -strip ' . escapeshellarg($stage1);
    $run = runCommand($cmd, $jobDir . '/' . $slot . '-normalize-error.txt');
    if (!is_file($stage1)) {
        throw new RuntimeException('تشخیص/صاف‌سازی خودکار کارت ناموفق بود: ' . $run['stderr']);
    }

    $size = @getimagesize($stage1);
    if (!$size) throw new RuntimeException('ابعاد کارت نرمال‌شده قابل خواندن نیست.');

    $final = $jobDir . '/' . $slot . '-card.png';
    if ((int)$size[1] > (int)$size[0]) {
        $cmd2 = escapeshellarg($convert) . ' ' . escapeshellarg($stage1)
            . ' -rotate 90 +repage -strip ' . escapeshellarg($final);
        runCommand($cmd2, $jobDir . '/' . $slot . '-rotate-error.txt');
    } else {
        @copy($stage1, $final);
    }
    if (!is_file($final)) throw new RuntimeException('ساخت تصویر نهایی کارت ناموفق بود.');
    $fs = @getimagesize($final);
    if (!$fs) throw new RuntimeException('تصویر نهایی کارت قابل خواندن نیست.');

    return [
        'slot'=>$slot,
        'file'=>$final,
        'width'=>(int)$fs[0],
        'height'=>(int)$fs[1],
        'preview'=>imageDataUri($final, 'image/png'),
    ];
}

function runWholeCardSet(array $config, string $convert, string $cardFile, string $jobDir, string $prefix): array
{
    $rows = [];
    $variants = ['original','autolevel'];
    $psms = [11,12];
    foreach ($variants as $variant) {
        $processed = $jobDir . '/' . $prefix . '-whole-' . $variant . '.png';
        $prep = preprocessImage($config, $convert, $cardFile, $processed, $variant, $jobDir . '/' . $prefix . '-whole-' . $variant . '-prep.txt');
        if (!is_file($processed)) continue;
        foreach ($psms as $psm) {
            $ocr = runOCR($config, $processed, 'fas+eng', $psm, $jobDir . '/' . $prefix . '-whole-' . $variant . '-psm' . $psm . '.txt');
            $rows[] = [
                'preprocess'=>$variant,
                'language'=>'fas+eng',
                'psm'=>$psm,
                'text'=>(string)$ocr['stdout'],
                'stderr'=>(string)$ocr['stderr'],
            ];
        }
    }
    return $rows;
}

function wholeTextQuality(string $text): int
{
    $clean = normalizeFaText($text);
    $letters = persianCharCount($clean);
    preg_match_all('/[A-Za-z0-9]/', $clean, $m);
    $latin = count((array)($m[0] ?? []));
    $lines = count(array_filter(preg_split('/\\R/u', $clean) ?: []));
    $junk = preg_match_all('/[^\\p{L}\\p{N}\\s:،,.\\-\\/]/u', $clean, $j);
    return $letters * 4 + $latin * 3 + $lines * 4 - (int)$junk;
}

function bestWholeCardText(array $wholeResults): string
{
    $best = '';
    $bestScore = -PHP_INT_MAX;
    foreach ($wholeResults as $row) {
        $text = trim((string)($row['text'] ?? ''));
        if ($text === '') continue;
        $score = wholeTextQuality($text);
        if ($score > $bestScore) { $bestScore = $score; $best = $text; }
    }
    return $best;
}

function resultFieldMap(array $autoResult): array
{
    $out = [];
    foreach ((array)($autoResult['fields'] ?? []) as $key=>$field) {
        $out[(string)$key] = (string)($field['best']['normalized'] ?? '');
    }
    if (($autoResult['template'] ?? '') === 'ownership_card' && isset($autoResult['plate'])) {
        $out['plate_full'] = (string)($autoResult['plate']['display'] ?? '');
    }
    return $out;
}

function humanFieldLabels(string $template): array
{
    if ($template === 'ownership_card') {
        return [
            'owner_name'=>'نام مالک',
            'national_id'=>'کد ملی / شناسه ملی',
            'vin'=>'VIN / شماره شاسی',
            'plate_two_digits'=>'پلاک — دو رقم',
            'plate_letter'=>'پلاک — حرف',
            'plate_three_digits'=>'پلاک — سه رقم',
            'plate_iran_code'=>'پلاک — ایران',
            'plate_full'=>'پلاک کامل',
        ];
    }
    return [
        'serial_number'=>'شماره مسلسل',
        'usage'=>'کاربری',
        'system'=>'سیستم',
        'vehicle_type'=>'تیپ',
        'model_year'=>'مدل / سال ساخت',
        'capacity'=>'ظرفیت',
        'fuel'=>'نوع سوخت',
        'color'=>'رنگ',
        'engine_number'=>'شماره موتور',
        'vin'=>'شماره شاسی / VIN',
        'room_number'=>'شماره اتاق',
        'barcode_number'=>'شماره زیر بارکد',
    ];
}

function autoAssignTwoSides(array $cards): array
{
    if (count($cards) <= 1) return $cards;
    $keys = array_keys($cards);
    $a = $cards[$keys[0]];
    $b = $cards[$keys[1]];
    $aScores = (array)($a['detection']['scores'] ?? []);
    $bScores = (array)($b['detection']['scores'] ?? []);
    $aDelta = (int)($aScores['ownership_card'] ?? 0) - (int)($aScores['vehicle_card'] ?? 0);
    $bDelta = (int)($bScores['ownership_card'] ?? 0) - (int)($bScores['vehicle_card'] ?? 0);

    /* If both OCR detections collapse to the same template, relative scores
       decide which image is the owner/plate side and which is technical. */
    if (($a['template'] ?? '') === ($b['template'] ?? '')) {
        if ($aDelta >= $bDelta) {
            $cards[$keys[0]]['template'] = 'ownership_card';
            $cards[$keys[1]]['template'] = 'vehicle_card';
        } else {
            $cards[$keys[0]]['template'] = 'vehicle_card';
            $cards[$keys[1]]['template'] = 'ownership_card';
        }
    }
    return $cards;
}

function exportTextForJob(array $combined): string
{
    $lines = [];
    $lines[] = 'گزارش OCR کارت خودرو';
    $lines[] = 'تاریخ: ' . date('Y-m-d H:i:s');
    $lines[] = str_repeat('=', 60);
    foreach ((array)($combined['cards'] ?? []) as $card) {
        $template = (string)($card['template'] ?? '');
        $title = $template === 'ownership_card'
            ? 'روی کارت خودرو — مالک / VIN / پلاک'
            : 'پشت کارت خودرو — مشخصات فنی';
        $lines[] = '';
        $lines[] = $title;
        $lines[] = str_repeat('-', 60);
        $labels = humanFieldLabels($template);
        foreach ((array)($card['fields'] ?? []) as $key=>$value) {
            $lines[] = ($labels[$key] ?? $key) . ': ' . (string)$value;
        }
        $lines[] = '';
        $lines[] = 'متن کامل OCR این کارت:';
        $lines[] = (string)($card['full_ocr'] ?? '');
        $lines[] = str_repeat('=', 60);
    }
    return implode("\n", $lines) . "\n";
}

function outputDownload(array $config, string $jobId, string $format): void
{
    $jobDir = assertJob($config, $jobId);
    if ($format === 'json') {
        $path = $jobDir . '/combined-result.json';
        $type = 'application/json; charset=utf-8';
        $name = 'vehicle-ocr-' . $jobId . '.json';
    } else {
        $path = $jobDir . '/combined-ocr.txt';
        $type = 'text/plain; charset=utf-8';
        $name = 'vehicle-ocr-' . $jobId . '.txt';
    }
    if (!is_file($path)) throw new RuntimeException('فایل خروجی هنوز ساخته نشده است.');
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$errors = [];
$combinedResult = null;
$jobId = '';

try {
    ensureDir($config['storage']);
    cleanupJobs($config['storage'], (int)$config['jobs_expire_seconds']);
    $errors = checkEnvironment($config);

    if (isset($_GET['download'], $_GET['job']) && count($errors) === 0) {
        outputDownload($config, (string)$_GET['job'], (string)$_GET['download']);
    }
} catch (Throwable $ex) {
    $errors[] = $ex->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($errors) === 0) {
    try {
        validateCsrf();
        if ((string)($_POST['action'] ?? '') !== 'process_pair') {
            throw new RuntimeException('Action نامعتبر است.');
        }

        $hasFront = isset($_FILES['front_image']) && (int)($_FILES['front_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasBack  = isset($_FILES['back_image']) && (int)($_FILES['back_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasFront && !$hasBack) throw new RuntimeException('حداقل یک تصویر انتخاب کن.');

        $jobId = newJobId();
        $jobDir = rtrim($config['storage'], '/') . '/' . $jobId;
        ensureDir($jobDir);
        $_SESSION['ocr_jobs'][$jobId] = time();
        $convert = findConvert();

        $cards = [];
        $uploads = [];
        if ($hasFront) $uploads['front_upload'] = $_FILES['front_image'];
        if ($hasBack) $uploads['back_upload'] = $_FILES['back_image'];

        foreach ($uploads as $slot=>$upload) {
            $card = normalizeCardImage($config, $convert, $upload, $jobDir, $slot);
            $whole = runWholeCardSet($config, $convert, $card['file'], $jobDir, $slot);
            $detection = detectCardTemplateDetailed($cardTemplates, $whole);
            $card['whole_results'] = $whole;
            $card['full_ocr'] = bestWholeCardText($whole);
            $card['detection'] = $detection;
            $card['template'] = (string)($detection['template'] ?? '');
            $cards[$slot] = $card;
        }

        $cards = autoAssignTwoSides($cards);
        $finalCards = [];
        foreach ($cards as $slot=>$card) {
            $template = (string)$card['template'];
            if (!isset($cardTemplates[$template])) {
                /* Slot semantics are a safe final fallback if OCR detection is too weak. */
                $template = $slot === 'front_upload' ? 'ownership_card' : 'vehicle_card';
            }
            $subDir = $jobDir . '/' . $slot . '-extract';
            ensureDir($subDir);
            $auto = runStructuredTemplateExtraction(
                $config, $template, $cardTemplates, $card['file'], $subDir, $card['whole_results']
            );
            $auto['detection'] = $card['detection'];
            $fields = resultFieldMap($auto);
            $finalCards[] = [
                'source_slot'=>$slot,
                'template'=>$template,
                'template_label'=>$cardTemplates[$template]['label'],
                'preview'=>$card['preview'],
                'width'=>$card['width'],
                'height'=>$card['height'],
                'fields'=>$fields,
                'full_ocr'=>$card['full_ocr'],
                'detection'=>$card['detection'],
                'ocr_runs'=>$auto['ocr_runs'] ?? 0,
            ];
        }

        /* Always present FRONT first, BACK second regardless of upload order. */
        usort($finalCards, function(array $a, array $b): int {
            $rank = ['ownership_card'=>0, 'vehicle_card'=>1];
            return ($rank[$a['template']] ?? 9) <=> ($rank[$b['template']] ?? 9);
        });

        $combinedResult = [
            'job_id'=>$jobId,
            'created_at'=>date('Y-m-d H:i:s'),
            'cards'=>$finalCards,
        ];
        jsonWrite($jobDir . '/combined-result.json', $combinedResult);
        file_put_contents($jobDir . '/combined-ocr.txt', exportTextForJob($combinedResult));
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OCR Lab v0.9</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f9;color:#172033;font-family:Tahoma,Arial,sans-serif}.container{max-width:1180px;margin:28px auto;padding:0 14px}.card{background:#fff;border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 6px 25px rgba(0,0,0,.06)}h1{margin:0 0 8px}.muted{color:#667085;line-height:1.9}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.uploadBox{border:1px solid #d0d5dd;border-radius:14px;padding:16px;background:#fcfcfd}.uploadBox strong{display:block;margin-bottom:7px}.uploadBox input{width:100%;padding:12px;border:1px dashed #98a2b3;border-radius:10px;background:#fff}.btn{display:inline-block;border:0;border-radius:11px;padding:13px 20px;background:#101828;color:#fff;text-decoration:none;cursor:pointer;font-size:14px}.btn.alt{background:#344054}.error{background:#fff1f0;color:#b42318;border:1px solid #fecdca;border-radius:12px;padding:12px;margin-bottom:12px}.success{background:#ecfdf3;color:#067647;border:1px solid #abefc6;border-radius:12px;padding:13px}.sideTitle{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.badge{background:#f2f4f7;padding:5px 9px;border-radius:8px;font-size:12px}.preview{width:100%;max-height:500px;object-fit:contain;background:#101828;border-radius:13px;padding:7px;margin:14px 0}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field label{display:block;font-size:13px;color:#475467;margin-bottom:6px}.field input{width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:11px 12px;background:#f9fafb;color:#101828;font-size:15px;direction:auto}.downloads{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}.note{background:#fffaeb;border:1px solid #fedf89;color:#93370d;border-radius:11px;padding:12px;line-height:1.8}@media(max-width:780px){.grid2,.fields{grid-template-columns:1fr}}
</style>
</head>
<body><div class="container">
<div class="card">
<h1>OCR Lab <span class="badge">v0.10</span></h1>
<div class="muted">دو سمت کارت خودرو را همزمان بارگذاری کن. انتخاب دستی چهار گوشه حذف شده؛ کارت به‌صورت خودکار نرمال، Trim و Deskew می‌شود، نوع سمت تشخیص داده می‌شود و هر مقدار داخل فیلد خودش قرار می‌گیرد. در v0.10 علاوه بر دقت روی کارت، کارت‌های فنی جدید با نور/فونت متفاوت هم با بازیابی بصری عدد ۲، سال، سوخت، سیستم، کاربری و شماره کنار بارکد دقیق‌تر تحلیل می‌شوند.</div>
</div>
<?php foreach ($errors as $err): ?><div class="error"><?= e((string)$err) ?></div><?php endforeach; ?>
<div class="card">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="process_pair">
<div class="grid2">
<div class="uploadBox"><strong>روی کارت — مالک / VIN / پلاک</strong><div class="muted">اگر جابه‌جا هم انتخاب شود، سیستم تلاش می‌کند سمت‌ها را خودکار تشخیص دهد.</div><br><input type="file" name="front_image" accept="image/*"></div>
<div class="uploadBox"><strong>پشت کارت — مشخصات فنی</strong><div class="muted">کاربری، سیستم، تیپ، مدل، سوخت، رنگ، موتور، VIN و سایر فیلدها.</div><br><input type="file" name="back_image" accept="image/*"></div>
</div>
<div class="note" style="margin-top:14px">برای بهترین نتیجه، مثل نمونه‌های جدیدت کارت تقریباً تمام کادر تصویر را پر کند و زاویه پرسپکتیو شدید نداشته باشد.</div>
<br><button class="btn" type="submit">استخراج هوشمند هر دو کارت</button>
</form>
</div>
<?php if (is_array($combinedResult)): ?>
<div class="card"><div class="success">استخراج کامل شد. اطلاعات روی کارت و پشت کارت به‌صورت جداگانه دسته‌بندی شدند.</div>
<div class="downloads"><a class="btn" href="?download=txt&amp;job=<?= e($jobId) ?>">دانلود OCR کامل TXT</a><a class="btn alt" href="?download=json&amp;job=<?= e($jobId) ?>">دانلود JSON ساختاریافته</a></div></div>
<?php foreach ((array)$combinedResult['cards'] as $card): $tpl=(string)$card['template']; $labels=humanFieldLabels($tpl); ?>
<div class="card">
<div class="sideTitle"><h2 style="margin:0"><?= e($tpl==='ownership_card' ? 'روی کارت خودرو — مالک / VIN / پلاک' : 'پشت کارت خودرو — مشخصات فنی') ?></h2><span class="badge">تشخیص خودکار: <?= e((string)($card['detection']['confidence'] ?? '')) ?></span></div>
<img class="preview" src="<?= e((string)$card['preview']) ?>" alt="card">
<div class="fields">
<?php foreach ((array)$card['fields'] as $key=>$value): ?>
<div class="field"><label><?= e((string)($labels[$key] ?? $key)) ?></label><input type="text" readonly value="<?= e((string)$value) ?>"></div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div></body></html>
