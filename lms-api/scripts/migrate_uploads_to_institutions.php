<?php
/**
 * Migrate legacy flat uploads into institution-scoped directories.
 *
 * Legacy layout:
 *   uploads/<category>/<filename>
 *   uploads/<category>/<subdir>/<filename>
 *
 * New layout:
 *   uploads/<institution_id>/<category>/<filename>
 *   uploads/<institution_id>/announcements/<announcement_uuid>/<filename>
 *
 * The script is a dry run by default. Pass --apply to copy files and update
 * database references. Files are copied, never moved, so a failed run can be
 * repeated safely.
 *
 * Usage:
 *   php scripts/migrate_uploads_to_institutions.php
 *   php scripts/migrate_uploads_to_institutions.php --apply
 *   php scripts/migrate_uploads_to_institutions.php --db-host=localhost --db-user=root --db-pass=
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$options = getopt('', [
    'apply',
    'help',
    'verbose',
    'db-host::',
    'db-port::',
    'db-name::',
    'db-user::',
    'db-pass::',
]);

// PHP's getopt() drops options supplied with an empty value (for example
// --db-pass=). Re-read the raw arguments so an empty password can override a
// non-empty value loaded from .env.
foreach ($_SERVER['argv'] ?? [] as $rawArg) {
    if (preg_match('/^--(db-[a-z-]+)=$/', (string) $rawArg, $matches)) {
        $options[$matches[1]] = '';
    }
}

if (isset($options['help'])) {
    echo <<<TXT
Legacy upload migration

Options:
  --apply             Copy files and update database references (default: dry run)
  --verbose           Print every plan item, including already migrated files
  --db-host=HOST      Override DB_HOST
  --db-port=PORT      Override DB_PORT
  --db-name=NAME      Override DB_NAME
  --db-user=USER      Override DB_USER
  --db-pass=PASS      Override DB_PASS
  --help              Show this help

TXT;
    exit(0);
}

$apply = array_key_exists('apply', $options);
$verbose = array_key_exists('verbose', $options);

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function optionValue(array $options, string $key, ?string $default): ?string
{
    if (array_key_exists($key, $options) && is_string($options[$key])) {
        return $options[$key];
    }

    return $default;
}

$dbHost = optionValue($options, 'db-host', envValue('DB_HOST', 'localhost'));
$dbPort = optionValue($options, 'db-port', envValue('DB_PORT', '3306'));
$dbName = optionValue($options, 'db-name', envValue('DB_NAME', 'lms'));
$dbUser = optionValue($options, 'db-user', envValue('DB_USER', 'root'));
$dbPass = optionValue($options, 'db-pass', envValue('DB_PASS', ''));

$uploadsRoot = realpath(__DIR__ . '/../uploads');
if ($uploadsRoot === false || !is_dir($uploadsRoot)) {
    fwrite(STDERR, "Uploads directory not found: " . __DIR__ . "/../uploads\n");
    exit(1);
}
$uploadsRoot = rtrim(str_replace('\\', '/', $uploadsRoot), '/');

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        (string) $dbUser,
        (string) $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "If you are running locally, try: --db-host=localhost --db-user=root --db-pass=\n");
    exit(1);
}

/**
 * @return array<int, string>
 */
function knownCategories(string $uploadsRoot): array
{
    // Categories that may appear in the database even when their legacy
    // directory is empty or missing (for example announcement attachments).
    $categories = ['announcements', 'chat-attachments'];

    foreach (scandir($uploadsRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!is_dir($uploadsRoot . '/' . $entry) || ctype_digit($entry)) {
            continue;
        }
        $categories[] = $entry;
    }

    return array_values(array_unique($categories));
}

$knownCategories = knownCategories($uploadsRoot);

function normalizeSlashes(string $value): string
{
    return str_replace('\\', '/', $value);
}

function isSafeRelativePath(string $relative): bool
{
    if ($relative === '' || str_contains($relative, "\0")) {
        return false;
    }

    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function sanitizeSegment(string $segment): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $segment);
    return $safe === '' ? '_' : $safe;
}

/**
 * Parse a stored upload value into its legacy relative path and original
 * URL format. Returns null when the value is not an upload reference.
 *
 * @param array<int, string> $knownCategories
 * @return array{relative:string,category:string,segments:array<int,string>,format:string}|null
 */
function parseUploadValue(string $rawValue, array $knownCategories, ?string $defaultCategory = null): ?array
{
    $value = trim($rawValue);
    if ($value === '') {
        return null;
    }

    $format = 'relative';

    if (preg_match('#^https?://#i', $value)) {
        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $value = $path;
        $format = 'absolute';
    }

    $value = normalizeSlashes($value);

    if (str_starts_with($value, '/api/upload/')) {
        $value = substr($value, strlen('/api/upload/'));
        $format = 'api';
    } elseif (str_starts_with($value, 'api/upload/')) {
        $value = substr($value, strlen('api/upload/'));
        $format = 'api';
    } elseif (str_starts_with($value, '/uploads/')) {
        $value = substr($value, strlen('/uploads/'));
        $format = 'absolute';
    } elseif (str_starts_with($value, 'uploads/')) {
        $value = substr($value, strlen('uploads/'));
        $format = 'absolute';
    } elseif (str_starts_with($value, '/')) {
        $format = 'absolute';
    }

    $value = ltrim($value, '/');
    $segments = array_values(array_filter(explode('/', $value), static function (string $segment): bool {
        return $segment !== '';
    }));

    if (count($segments) === 1 && $defaultCategory !== null) {
        $segments = [$defaultCategory, $segments[0]];
    }

    if (count($segments) < 2) {
        return null;
    }

    $category = $segments[0];
    if (!in_array($category, $knownCategories, true)) {
        return null;
    }

    return [
        'relative' => implode('/', $segments),
        'category' => $category,
        'segments' => $segments,
        'format' => $format,
    ];
}

/**
 * @param array{relative:string,category:string,segments:array<int,string>,format:string} $parsed
 */
function buildNewRelative(array $parsed, int $institutionId): string
{
    $segments = $parsed['segments'];
    $category = sanitizeSegment($parsed['category']);
    $filename = sanitizeSegment((string) end($segments));

    if ($parsed['category'] === 'announcements' && count($segments) >= 3) {
        $subdir = sanitizeSegment((string) $segments[1]);
        return $institutionId . '/announcements/' . $subdir . '/' . $filename;
    }

    return $institutionId . '/' . $category . '/' . $filename;
}

/**
 * @param array{relative:string,category:string,segments:array<int,string>,format:string} $parsed
 */
function buildNewValue(array $parsed, int $institutionId): string
{
    $newRelative = buildNewRelative($parsed, $institutionId);

    return match ($parsed['format']) {
        'api' => '/api/upload/' . $newRelative,
        'absolute' => '/uploads/' . $newRelative,
        default => $newRelative,
    };
}

$referenceQueries = [
    [
        'label' => 'assignments.file_path',
        'table' => 'assignments',
        'id_column' => 'assignment_id',
        'column' => 'file_path',
        'default_category' => 'assignments',
        'sql' => "SELECT a.assignment_id AS row_id, a.file_path AS value, cs.institution_id AS institution_id
                  FROM assignments a
                  LEFT JOIN class_subjects cs ON cs.course_id = a.course_id
                  WHERE a.file_path IS NOT NULL AND a.file_path <> ''",
    ],
    [
        'label' => 'assignment_submissions.submission_file',
        'table' => 'assignment_submissions',
        'id_column' => 'submission_id',
        'column' => 'submission_file',
        'default_category' => 'assignments',
        'sql' => "SELECT s.submission_id AS row_id, s.submission_file AS value,
                         COALESCE(cs.institution_id, cs2.institution_id) AS institution_id
                  FROM assignment_submissions s
                  LEFT JOIN assignments a ON a.assignment_id = s.assignment_id
                  LEFT JOIN class_subjects cs ON cs.course_id = a.course_id
                  LEFT JOIN class_subjects cs2 ON cs2.course_id = s.course_id
                  WHERE s.submission_file IS NOT NULL AND s.submission_file <> ''",
    ],
    [
        'label' => 'course_materials.file_path',
        'table' => 'course_materials',
        'id_column' => 'material_id',
        'column' => 'file_path',
        'default_category' => 'materials',
        'sql' => "SELECT m.material_id AS row_id, m.file_path AS value, cs.institution_id AS institution_id
                  FROM course_materials m
                  LEFT JOIN class_subjects cs ON cs.course_id = m.course_id
                  WHERE m.file_path IS NOT NULL AND m.file_path <> ''",
    ],
    [
        'label' => 'assessment_submissions.file_path',
        'table' => 'assessment_submissions',
        'id_column' => 'submission_id',
        'column' => 'file_path',
        'default_category' => null,
        'sql' => "SELECT s.submission_id AS row_id, s.file_path AS value, cs.institution_id AS institution_id
                  FROM assessment_submissions s
                  LEFT JOIN assessments a ON a.assessment_id = s.assessment_id
                  LEFT JOIN class_subjects cs ON cs.course_id = a.course_id
                  WHERE s.file_path IS NOT NULL AND s.file_path <> ''",
    ],
    [
        'label' => 'quiz_questions.image_question',
        'table' => 'quiz_questions',
        'id_column' => 'question_id',
        'column' => 'image_question',
        'default_category' => 'quiz-questions',
        'sql' => "SELECT qq.question_id AS row_id, qq.image_question AS value, cs.institution_id AS institution_id
                  FROM quiz_questions qq
                  LEFT JOIN quizzes q ON q.quiz_id = qq.quiz_id
                  LEFT JOIN class_subjects cs ON cs.course_id = q.course_id
                  WHERE qq.image_question IS NOT NULL AND qq.image_question <> ''",
    ],
    [
        // Phase 1B: new uploads are attached to the bank row, not the legacy
        // per-quiz question. Institution is stored on the question itself.
        'label' => 'questions.image_question',
        'table' => 'questions',
        'id_column' => 'question_id',
        'column' => 'image_question',
        'default_category' => 'quiz-questions',
        'sql' => "SELECT question_id AS row_id, image_question AS value, institution_id AS institution_id
                  FROM questions
                  WHERE image_question IS NOT NULL AND image_question <> ''",
    ],
    [
        'label' => 'users.profile_photo',
        'table' => 'users',
        'id_column' => 'user_id',
        'column' => 'profile_photo',
        'default_category' => 'profiles',
        'sql' => "SELECT user_id AS row_id, profile_photo AS value, institution_id AS institution_id
                  FROM users WHERE profile_photo IS NOT NULL AND profile_photo <> ''",
    ],
    [
        'label' => 'institution_settings.logo_url',
        'table' => 'institution_settings',
        'id_column' => 'setting_id',
        'column' => 'logo_url',
        'default_category' => 'institutions',
        'sql' => "SELECT setting_id AS row_id, logo_url AS value, institution_id AS institution_id
                  FROM institution_settings WHERE logo_url IS NOT NULL AND logo_url <> ''",
    ],
    [
        'label' => 'institution_settings.banner_url',
        'table' => 'institution_settings',
        'id_column' => 'setting_id',
        'column' => 'banner_url',
        'default_category' => 'institutions',
        'sql' => "SELECT setting_id AS row_id, banner_url AS value, institution_id AS institution_id
                  FROM institution_settings WHERE banner_url IS NOT NULL AND banner_url <> ''",
    ],
    [
        'label' => 'subjects.image',
        'table' => 'subjects',
        'id_column' => 'subject_id',
        'column' => 'image',
        'default_category' => null,
        'sql' => "SELECT subject_id AS row_id, image AS value, institution_id AS institution_id
                  FROM subjects WHERE image IS NOT NULL AND image <> ''",
    ],
    [
        'label' => 'chat_rooms.room_avatar',
        'table' => 'chat_rooms',
        'id_column' => 'room_id',
        'column' => 'room_avatar',
        'default_category' => 'chat-avatars',
        'sql' => "SELECT room_id AS row_id, room_avatar AS value, institution_id AS institution_id
                  FROM chat_rooms WHERE room_avatar IS NOT NULL AND room_avatar <> ''",
    ],
    [
        'label' => 'chat_attachments.file_path',
        'table' => 'chat_attachments',
        'id_column' => 'attachment_id',
        'column' => 'file_path',
        'default_category' => 'chat-attachments',
        'sql' => "SELECT ca.attachment_id AS row_id, ca.file_path AS value, cr.institution_id AS institution_id
                  FROM chat_attachments ca
                  LEFT JOIN chat_messages cm ON cm.chat_message_id = ca.chat_message_id
                  LEFT JOIN chat_rooms cr ON cr.room_id = cm.room_id
                  WHERE ca.file_path IS NOT NULL AND ca.file_path <> ''",
    ],
];

$reports = [];
$report = static function (string $type, string $message) use (&$reports): void {
    $reports[] = ['type' => $type, 'message' => $message];
};

/**
 * Plan keyed by legacy relative path + institution.
 *
 * @var array<string, array{
 *   source_relative:string,
 *   source_path:string,
 *   institution_id:int,
 *   new_relative:string,
 *   references:array<int, array<string, mixed>>,
 *   inferred:bool
 * }>
 */
$plans = [];

$addPlan = static function (string $sourceRelative, int $institutionId, array $parsed, array $reference, bool $inferred = false) use (&$plans, $uploadsRoot): void {
    $key = $sourceRelative . '|' . $institutionId;
    if (!isset($plans[$key])) {
        $plans[$key] = [
            'source_relative' => $sourceRelative,
            'source_path' => $uploadsRoot . '/' . $sourceRelative,
            'institution_id' => $institutionId,
            'new_relative' => buildNewRelative($parsed, $institutionId),
            'references' => [],
            'inferred' => $inferred,
        ];
    }

    if ($reference !== []) {
        $plans[$key]['references'][] = $reference;
    }
};

$skipped = 0;
$unparsed = 0;

foreach ($referenceQueries as $query) {
    try {
        $rows = $pdo->query($query['sql'])->fetchAll();
    } catch (Throwable $e) {
        $report('ERROR', "Could not read {$query['label']}: " . $e->getMessage());
        continue;
    }

    foreach ($rows as $row) {
        $rowId = (int) ($row['row_id'] ?? 0);
        $rawValue = (string) ($row['value'] ?? '');
        $institutionId = (int) ($row['institution_id'] ?? 0);

        if ($rowId <= 0 || $rawValue === '') {
            continue;
        }

        $parsed = parseUploadValue($rawValue, $knownCategories, $query['default_category']);
        if ($parsed === null) {
            $unparsed++;
            if ($verbose) {
                $report('SKIP', "{$query['label']} #{$rowId}: not an upload path: {$rawValue}");
            }
            continue;
        }

        if ($institutionId <= 0) {
            $skipped++;
            $report('AMBIGUOUS', "{$query['label']} #{$rowId}: no institution for {$rawValue}");
            continue;
        }

        $newValue = buildNewValue($parsed, $institutionId);
        $addPlan($parsed['relative'], $institutionId, $parsed, [
            'label' => $query['label'],
            'table' => $query['table'],
            'id_column' => $query['id_column'],
            'row_id' => $rowId,
            'column' => $query['column'],
            'old_value' => $rawValue,
            'new_value' => $newValue,
        ]);
    }
}

// Announcement attachments are stored as JSON arrays of metadata objects.
try {
    $announcements = $pdo->query(
        "SELECT announcement_id, institution_id, attachments
         FROM announcements
         WHERE attachments IS NOT NULL AND attachments <> ''"
    )->fetchAll();
} catch (Throwable $e) {
    $announcements = [];
    $report('ERROR', 'Could not read announcements.attachments: ' . $e->getMessage());
}

foreach ($announcements as $announcement) {
    $announcementId = (int) ($announcement['announcement_id'] ?? 0);
    $institutionId = (int) ($announcement['institution_id'] ?? 0);
    $rawJson = (string) ($announcement['attachments'] ?? '');

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        $report('SKIP', "announcements.attachments #{$announcementId}: invalid JSON");
        continue;
    }

    if ($institutionId <= 0) {
        $report('AMBIGUOUS', "announcements.attachments #{$announcementId}: global announcement has no institution");
        continue;
    }

    $changed = false;
    $changedPlanKeys = [];

    foreach ($decoded as $index => $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $url = trim((string) ($attachment['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $parsed = parseUploadValue($url, $knownCategories);
        if ($parsed === null) {
            if ($verbose) {
                $report('SKIP', "announcements.attachments #{$announcementId}[{$index}]: not an upload path: {$url}");
            }
            continue;
        }

        $newUrl = buildNewValue($parsed, $institutionId);
        if ($newUrl === $url) {
            continue;
        }

        $addPlan($parsed['relative'], $institutionId, $parsed, []);
        $changedPlanKeys[] = $parsed['relative'] . '|' . $institutionId;
        $decoded[$index]['url'] = $newUrl;
        $changed = true;
    }

    if ($changed) {
        $newJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($newJson === false) {
            $report('ERROR', "announcements.attachments #{$announcementId}: failed to encode JSON");
            continue;
        }

        // Attach the single JSON update to the first plan created for this
        // announcement. The update only runs when that file was copied or was
        // already present, so a missing source never rewrites the JSON.
        $changedPlanKeys = array_values(array_unique($changedPlanKeys));
        $attached = false;
        foreach ($changedPlanKeys as $key) {
            if (!isset($plans[$key])) {
                continue;
            }
            $plans[$key]['references'][] = [
                'label' => 'announcements.attachments',
                'table' => 'announcements',
                'id_column' => 'announcement_id',
                'row_id' => $announcementId,
                'column' => 'attachments',
                'old_value' => $rawJson,
                'new_value' => $newJson,
            ];
            $attached = true;
            break;
        }

        if (!$attached) {
            $report('ERROR', "announcements.attachments #{$announcementId}: could not register JSON update");
        }
    }
}

// Discover files on disk that are still in the legacy flat layout.
$scannedFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $absolute = normalizeSlashes($file->getPathname());
    if (!str_starts_with($absolute, $uploadsRoot . '/')) {
        continue;
    }

    $relative = substr($absolute, strlen($uploadsRoot) + 1);
    if (!isSafeRelativePath($relative)) {
        $report('SKIP', "Unsafe file path: {$relative}");
        continue;
    }

    $segments = explode('/', $relative);
    if (ctype_digit($segments[0])) {
        // Already institution-scoped.
        continue;
    }

    $scannedFiles[$relative] = $absolute;
}

$institutionCodeToId = [];
try {
    foreach ($pdo->query('SELECT institution_id, institution_code FROM institutions')->fetchAll() as $row) {
        $code = strtolower(trim((string) ($row['institution_code'] ?? '')));
        if ($code !== '') {
            $institutionCodeToId[$code] = (int) $row['institution_id'];
        }
    }
} catch (Throwable $e) {
    $report('ERROR', 'Could not read institution codes: ' . $e->getMessage());
}

foreach ($scannedFiles as $relative => $absolute) {
    if (isset($plans[$relative . '|' . 0])) {
        continue;
    }

    $alreadyPlanned = false;
    foreach ($plans as $plan) {
        if ($plan['source_relative'] === $relative) {
            $alreadyPlanned = true;
            break;
        }
    }
    if ($alreadyPlanned) {
        continue;
    }

    $segments = explode('/', $relative);
    $category = $segments[0] ?? '';

    // Infer institution from uploads/institutions/<institution_code>/<file>.
    if ($category === 'institutions' && count($segments) >= 3) {
        $code = strtolower($segments[1]);
        if (isset($institutionCodeToId[$code])) {
            $parsed = parseUploadValue($relative, $knownCategories);
            if ($parsed !== null) {
                $addPlan($relative, $institutionCodeToId[$code], $parsed, [], true);
                continue;
            }
        }
    }

    $report('UNREFERENCED', "No database reference for uploads/{$relative}");
}

// Summarize and apply the plan.
$copyCount = 0;
$alreadyCount = 0;
$missingCount = 0;
$collisionCount = 0;
$dbUpdateCount = 0;
$dbSkippedCount = 0;

$planLines = [];

foreach ($plans as $key => $plan) {
    $sourcePath = $plan['source_path'];
    $destPath = $uploadsRoot . '/' . $plan['new_relative'];
    $sourceExists = is_file($sourcePath);
    $destExists = is_file($destPath);

    if (!$sourceExists) {
        $missingCount++;
        $planLines[] = sprintf(
            '[MISSING] uploads/%s (institution %d) -> uploads/%s',
            $plan['source_relative'],
            $plan['institution_id'],
            $plan['new_relative']
        );
        $plans[$key]['status'] = 'missing';
        continue;
    }

    if ($destExists) {
        $sourceHash = hash_file('sha256', $sourcePath);
        $destHash = hash_file('sha256', $destPath);
        if ($sourceHash === $destHash) {
            $alreadyCount++;
            $planLines[] = sprintf(
                '[EXISTS]  uploads/%s -> uploads/%s',
                $plan['source_relative'],
                $plan['new_relative']
            );
            $plans[$key]['status'] = 'exists';
            continue;
        }

        $collisionCount++;
        $planLines[] = sprintf(
            '[COLLISION] uploads/%s -> uploads/%s (destination exists with different content)',
            $plan['source_relative'],
            $plan['new_relative']
        );
        $plans[$key]['status'] = 'collision';
        continue;
    }

    $planLines[] = sprintf(
        '[COPY]    uploads/%s -> uploads/%s',
        $plan['source_relative'],
        $plan['new_relative']
    );
    $plans[$key]['status'] = 'copy';
    $copyCount++;
}

foreach ($reports as $entry) {
    $planLines[] = '[' . $entry['type'] . '] ' . $entry['message'];
}

echo "Legacy upload migration" . ($apply ? ' (APPLY)' : ' (DRY RUN)') . "\n";
echo "Uploads root: {$uploadsRoot}\n";
echo "Database: {$dbName}@{$dbHost}:{$dbPort}\n";
echo str_repeat('-', 72) . "\n";

foreach ($planLines as $line) {
    echo $line . "\n";
}

echo str_repeat('-', 72) . "\n";

$unreferencedCount = 0;
$ambiguousCount = 0;
foreach ($reports as $entry) {
    if ($entry['type'] === 'UNREFERENCED') {
        $unreferencedCount++;
    } elseif ($entry['type'] === 'AMBIGUOUS') {
        $ambiguousCount++;
    }
}

printf(
    "Files scanned: %d | to copy: %d | already migrated: %d | missing: %d | collisions: %d | unreferenced: %d | ambiguous: %d\n",
    count($scannedFiles),
    $copyCount,
    $alreadyCount,
    $missingCount,
    $collisionCount,
    $unreferencedCount,
    $ambiguousCount
);

$actionablePlans = array_filter($plans, static function (array $plan): bool {
    return in_array($plan['status'] ?? '', ['copy', 'exists'], true);
});

$referenceCount = 0;
foreach ($actionablePlans as $plan) {
    $referenceCount += count($plan['references']);
}
printf("Database references to update: %d\n", $referenceCount);

if (!$apply) {
    echo "Dry run complete. Re-run with --apply to copy files and update references.\n";
    exit(0);
}

// Copy files first. Database updates only run for files that are present.
foreach ($actionablePlans as $key => $plan) {
    if (($plan['status'] ?? '') === 'exists') {
        continue;
    }

    $destPath = $uploadsRoot . '/' . $plan['new_relative'];
    $destDir = dirname($destPath);
    if (!is_dir($destDir) && !mkdir($destDir, 0777, true) && !is_dir($destDir)) {
        $report('ERROR', "Could not create directory: {$destDir}");
        $plans[$key]['status'] = 'error';
        continue;
    }

    if (!copy($plan['source_path'], $destPath)) {
        $report('ERROR', "Could not copy uploads/{$plan['source_relative']} to uploads/{$plan['new_relative']}");
        $plans[$key]['status'] = 'error';
    }
}

$pdo->beginTransaction();
$appliedUpdates = 0;
$failedUpdates = 0;

try {
    foreach ($plans as $plan) {
        $status = $plan['status'] ?? '';
        if (!in_array($status, ['copy', 'exists'], true)) {
            continue;
        }

        foreach ($plan['references'] as $reference) {
            $sql = sprintf(
                'UPDATE `%s` SET `%s` = :new_value WHERE `%s` = :row_id AND `%s` = :old_value',
                str_replace('`', '', (string) $reference['table']),
                str_replace('`', '', (string) $reference['column']),
                str_replace('`', '', (string) $reference['id_column']),
                str_replace('`', '', (string) $reference['column'])
            );

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'new_value' => $reference['new_value'],
                'row_id' => $reference['row_id'],
                'old_value' => $reference['old_value'],
            ]);

            if ($stmt->rowCount() > 0) {
                $appliedUpdates++;
            } else {
                $dbSkippedCount++;
                $report(
                    'SKIP',
                    "{$reference['label']} #{$reference['row_id']}: value changed or already updated"
                );
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Database update failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($reports as $entry) {
    if (in_array($entry['type'], ['ERROR', 'SKIP'], true)) {
        echo '[' . $entry['type'] . '] ' . $entry['message'] . "\n";
    }
}

echo str_repeat('-', 72) . "\n";
printf("Copied files: %d | already present: %d | database updates applied: %d | database updates skipped: %d\n",
    $copyCount,
    $alreadyCount,
    $appliedUpdates,
    $dbSkippedCount
);
echo "Migration complete. Legacy files were copied, not moved.\n";
