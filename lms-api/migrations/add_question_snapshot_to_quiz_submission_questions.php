<?php
/**
 * Migration: add quiz_submission_questions.question_snapshot.
 * Run this from the repository root with:
 *   php lms-api/migrations/add_question_snapshot_to_quiz_submission_questions.php
 *
 * Phase 1B freezes the content a student was actually shown, so a later edit
 * to the question bank cannot rewrite history. The snapshot is written when an
 * attempt starts; this migration only makes room for it.
 */

require_once __DIR__ . '/../vendor/autoload.php';

function qsq_env(string $key, $default = null)
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $default;
}

// Load lms-api/.env only when the caller has not supplied database settings.
$envFile = __DIR__ . '/../.env';
$envProvided = getenv('DB_HOST') !== false || getenv('DB_NAME') !== false;
if (!$envProvided && file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') && strpos($line, '#') === false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($value);
            }
        }
    }
}

try {
    $host = qsq_env('DB_HOST', 'localhost');
    $dbname = qsq_env('DB_NAME', 'lms');
    $user = qsq_env('DB_USER', 'root');
    $pass = qsq_env('DB_PASS', '');
    $port = qsq_env('DB_PORT', 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Running migration: add question_snapshot to quiz_submission_questions...\n";

    $tableExists = (bool) $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'quiz_submission_questions'
    ")->fetchColumn();

    if (!$tableExists) {
        echo "quiz_submission_questions does not exist yet. Nothing to do.\n";
        exit(0);
    }

    $columnExists = (bool) $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'quiz_submission_questions'
          AND COLUMN_NAME = 'question_snapshot'
    ")->fetchColumn();

    if ($columnExists) {
        echo "Column question_snapshot already exists. Skipping migration.\n";
        exit(0);
    }

    $pdo->exec("
        ALTER TABLE quiz_submission_questions
        ADD COLUMN question_snapshot json DEFAULT NULL AFTER position
    ");

    echo "Migration completed successfully.\n";
    exit(0);
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
