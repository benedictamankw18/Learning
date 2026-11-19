<?php
/**
 * Migration: Add server-side progress tracking for "Prevent Going Back".
 * Run with: php migrations/add_quiz_submission_progress.php
 *
 * furthest_position on quiz_submissions records the highest question index a
 * student reached. locked_answer / answer_locked on quiz_submission_questions
 * capture the answer as it was when the student left each question, so a stale
 * tab cannot change an earlier answer after the setting is switched on.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
        if (strpos($line, '=') && !strpos($line, '#')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            // Explicit environment variables win over .env so the migration
            // can also be run against a local XAMPP database from the CLI.
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

    echo "🔄 Running migration: add quiz submission progress tracking...\n";

    $addColumn = static function (PDO $pdo, string $table, string $column, string $definition): void {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
        $stmt->execute(['column' => $column]);
        if ($stmt->fetch()) {
            echo "  ✓ {$table}.{$column} already exists\n";
            return;
        }

        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        echo "  → Added {$table}.{$column}\n";
    };

    $addColumn(
        $pdo,
        'quiz_submissions',
        'furthest_position',
        "INT(11) NOT NULL DEFAULT 0 COMMENT 'Prevent Going Back: highest 0-based question index reached' AFTER attempt"
    );
    $addColumn(
        $pdo,
        'quiz_submission_questions',
        'locked_answer',
        "TEXT DEFAULT NULL COMMENT 'Prevent Going Back: answer captured when this question was left' AFTER question_version_id"
    );
    $addColumn(
        $pdo,
        'quiz_submission_questions',
        'answer_locked',
        "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Prevent Going Back: whether locked_answer is authoritative' AFTER locked_answer"
    );

    echo "✅ Migration completed successfully!\n";
    exit(0);
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
