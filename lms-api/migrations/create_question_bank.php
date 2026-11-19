<?php
/**
 * Migration: create the Phase 1B central question bank and copy legacy data.
 * Run this from the repository root with:
 *   php lms-api/migrations/create_question_bank.php
 *
 * What it does (additive only, safe to re-run):
 *   1. Creates `questions`, `question_bank_options` and `quiz_question_links`
 *      from db/init/05-question-bank.sql.
 *   2. Copies every `quiz_questions` row into `questions`, keeping the
 *      original question_id.
 *   3. Copies `quiz_question_options` into `question_bank_options`, keeping the
 *      original option_id. Orphaned options are skipped.
 *   4. Links each quiz to its questions in `quiz_question_links`, keeping the
 *      authored order in `position`.
 *
 * Legacy rows are never deleted or rewritten. Duplicate question text is kept
 * as-is: the migration is a faithful copy, not a cleanup.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Read an environment value.
 *
 * Values loaded from lms-api/.env land in $_ENV; values supplied by the
 * process environment are read with getenv(). Empty strings count as unset so
 * an empty database password keeps working.
 */
function qbank_env(string $key, $default = null)
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
// This keeps the script usable from a checkout, from Docker (where the
// container environment wins), and from the test suite against a disposable
// database.
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

$schemaFile = dirname(__DIR__, 2) . '/db/init/05-question-bank.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "Could not find db/init/05-question-bank.sql.\n");
    fwrite(STDERR, "Run this migration from a repository checkout: php lms-api/migrations/create_question_bank.php\n");
    exit(1);
}

try {
    $host = qbank_env('DB_HOST', 'localhost');
    $dbname = qbank_env('DB_NAME', 'lms');
    $user = qbank_env('DB_USER', 'root');
    $pass = qbank_env('DB_PASS', '');
    $port = qbank_env('DB_PORT', 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Running migration: create the central question bank...\n";

    // 1. Tables. The init file is the single source of truth for the schema.
    $pdo->exec((string) file_get_contents($schemaFile));
    echo "  -> question bank tables are in place\n";

    // 2. Questions. Institution and owner come from the quiz's course.
    $pdo->beginTransaction();

    $insertQuestions = $pdo->exec("
        INSERT INTO questions (
            question_id, institution_id, owner_user_id, question_text,
            image_question, question_type, points, difficulty, explanation,
            correct_answer, created_at, updated_at
        )
        SELECT
            qq.question_id,
            cs.institution_id,
            t.user_id,
            qq.question_text,
            qq.image_question,
            qq.question_type,
            COALESCE(qq.points, 1),
            qq.difficulty,
            qq.explanation,
            qq.correct_answer,
            COALESCE(qq.created_at, NOW()),
            COALESCE(qq.updated_at, COALESCE(qq.created_at, NOW()))
        FROM quiz_questions qq
        INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
        INNER JOIN class_subjects cs ON cs.course_id = q.course_id
        LEFT JOIN teachers t ON t.teacher_id = cs.teacher_id
        WHERE NOT EXISTS (
            SELECT 1 FROM questions existing WHERE existing.question_id = qq.question_id
        )
    ");

    // 3. Options. Only copy options whose question actually made it across.
    $insertOptions = $pdo->exec("
        INSERT INTO question_bank_options (
            option_id, question_id, option_label, option_text, is_correct, created_at
        )
        SELECT
            o.option_id,
            o.question_id,
            o.option_label,
            o.option_text,
            COALESCE(o.is_correct, 0),
            COALESCE(o.created_at, NOW())
        FROM quiz_question_options o
        INNER JOIN questions qn ON qn.question_id = o.question_id
        WHERE NOT EXISTS (
            SELECT 1 FROM question_bank_options existing WHERE existing.option_id = o.option_id
        )
    ");

    // 4. Links. position mirrors the legacy order_index. points_override stays
    //    NULL because questions.points already carries the same value; future
    //    per-quiz edits set it explicitly.
    $insertLinks = $pdo->exec("
        INSERT INTO quiz_question_links (quiz_id, question_id, position, points_override)
        SELECT qq.quiz_id, qq.question_id, COALESCE(qq.order_index, 0), NULL
        FROM quiz_questions qq
        INNER JOIN questions qn ON qn.question_id = qq.question_id
        WHERE NOT EXISTS (
            SELECT 1 FROM quiz_question_links existing
            WHERE existing.quiz_id = qq.quiz_id
              AND existing.question_id = qq.question_id
        )
    ");

    $pdo->commit();

    echo "  -> questions copied: {$insertQuestions}\n";
    echo "  -> options copied:   {$insertOptions}\n";
    echo "  -> quiz links added: {$insertLinks}\n";

    // Report anything that could not be resolved so the operator can decide
    // what to do with it. Nothing is deleted.
    $skippedQuestions = (int) $pdo->query("
        SELECT COUNT(*)
        FROM quiz_questions qq
        INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
        LEFT JOIN class_subjects cs ON cs.course_id = q.course_id
        WHERE cs.course_id IS NULL
    ")->fetchColumn();

    $skippedOptions = (int) $pdo->query("
        SELECT COUNT(*)
        FROM quiz_question_options o
        LEFT JOIN quiz_questions qq ON qq.question_id = o.question_id
        WHERE qq.question_id IS NULL
    ")->fetchColumn();

    if ($skippedQuestions > 0) {
        echo "  ! {$skippedQuestions} question(s) could not be linked to an institution (missing quiz course)\n";
    }
    if ($skippedOptions > 0) {
        echo "  ! {$skippedOptions} option(s) have no matching question and were left behind\n";
    }

    echo "Migration completed successfully.\n";
    exit(0);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
