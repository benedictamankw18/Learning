<?php
/**
 * Migration: link bank questions to lesson-plan Topic / Unit / Week buckets.
 * Run from the repository root:
 *   php lms-api/migrations/add_course_section_to_questions.php
 *
 * The Question Bank now organizes questions with the same course_sections rows
 * managed by Lesson Plans. This migration is additive and idempotent:
 *   1. adds questions.course_section_id;
 *   2. adds idx_questions_course_section.
 *
 * Existing questions keep a NULL value. Legacy category_id, subject_id and
 * topic_id columns are intentionally left in place for compatibility.
 */

function qsection_env(string $key, $default = null)
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

$envFile = __DIR__ . '/../.env';
$envProvided = getenv('DB_HOST') !== false || getenv('DB_NAME') !== false;
if (!$envProvided && file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') && strpos($line, '#') === false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($value);
            }
        }
    }
}

/** @return array<string,true> */
function qsection_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ");
    $stmt->execute(['table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[(string) $column] = true;
    }

    return $columns;
}

/** @return array<string,true> */
function qsection_indexes(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ");
    $stmt->execute(['table' => $table]);

    $indexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $index) {
        $indexes[(string) $index] = true;
    }

    return $indexes;
}

function qsection_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ");
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

try {
    $host = qsection_env('DB_HOST', 'localhost');
    $dbname = qsection_env('DB_NAME', 'lms');
    $user = qsection_env('DB_USER', 'root');
    $pass = qsection_env('DB_PASS', '');
    $port = qsection_env('DB_PORT', 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Running migration: add lesson-plan course section to questions...\n";

    if (!qsection_table_exists($pdo, 'questions')) {
        echo "The questions table is missing. Run create_question_bank.php first.\n";
        exit(1);
    }

    $columns = qsection_columns($pdo, 'questions');
    if (!isset($columns['course_section_id'])) {
        $after = isset($columns['topic_id']) ? ' AFTER `topic_id`' : '';
        $pdo->exec(
            'ALTER TABLE `questions` ADD COLUMN `course_section_id` int(11) DEFAULT NULL' . $after
        );
        echo "  -> added questions.course_section_id\n";
    } else {
        echo "  -> questions.course_section_id already exists\n";
    }

    $indexes = qsection_indexes($pdo, 'questions');
    if (!isset($indexes['idx_questions_course_section'])) {
        $pdo->exec(
            'ALTER TABLE `questions` ADD KEY `idx_questions_course_section` (`course_section_id`)'
        );
        echo "  -> added idx_questions_course_section\n";
    } else {
        echo "  -> idx_questions_course_section already exists\n";
    }

    echo "Migration completed successfully.\n";
    exit(0);
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
