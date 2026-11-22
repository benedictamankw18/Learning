<?php
// fixerMarker: 2026-11-22 quiz-share-export-results-bank-picker
/**
 * Migration: create the quiz_shares table.
 * Run with: php lms-api/migrations/create_quiz_shares.php
 *
 * A quiz keeps its original quizzes.course_id. This table stores the extra
 * teacher-assigned class subjects that may also see and take the quiz.
 *
 * The migration is additive and idempotent, so it is safe on a fresh database
 * where db/init/01-lms.sql already declares the table.
 */

// Same argument style as tests/run.php, so an empty local password works on
// Windows where empty environment variables are dropped.
$cliOptions = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/^--db-(host|port|name|user|pass)=(.*)$/', $argument, $matches) === 1) {
        $cliOptions['DB_' . strtoupper($matches[1])] = $matches[2];
    }
}

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') && strpos($line, '#') === false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!array_key_exists($key, $cliOptions) && getenv($key) === false && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($value);
            }
        }
    }
}

$envValue = static function (string $key, string $default) use ($cliOptions): string {
    if (array_key_exists($key, $cliOptions)) {
        return $cliOptions[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return isset($_ENV[$key]) && $_ENV[$key] !== '' ? (string) $_ENV[$key] : $default;
};

try {
    $host = $envValue('DB_HOST', 'localhost');
    $dbname = $envValue('DB_NAME', 'lms');
    $user = $envValue('DB_USER', 'root');
    $pass = $envValue('DB_PASS', '');
    $port = $envValue('DB_PORT', '3306');

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Running migration: create quiz shares table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `quiz_shares` (
            `quiz_id` int(11) NOT NULL,
            `course_id` int(11) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`quiz_id`,`course_id`),
            KEY `idx_quiz_shares_course_id` (`course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "  OK quiz_shares is ready\n";
    echo "Migration complete.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
