<?php
/**
 * Migration: add Phase 1C question bank metadata, taxonomy and versions.
 * Run from the repository root:
 *   php lms-api/migrations/add_question_bank_metadata.php
 *
 * This migration is additive and idempotent:
 *   1. executes db/init/06-question-bank-metadata.sql (new tables only);
 *   2. adds the metadata columns declared in 05-question-bank.sql and
 *      01-lms.sql to an existing database;
 *   3. grandfathers existing questions as institution-visible/approved and
 *      freezes their current content as version 1.
 *
 * Attempt rows keep their JSON snapshot; question_version_id is left NULL for
 * them because their snapshot cannot be mapped to a version reliably.
 */

function qmeta_env(string $key, $default = null)
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

$schemaFile = dirname(__DIR__, 2) . '/db/init/06-question-bank-metadata.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "Could not find db/init/06-question-bank-metadata.sql.\n");
    exit(1);
}

/** @return array<string,true> */
function qmeta_columns(PDO $pdo, string $table): array
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

try {
    $host = qmeta_env('DB_HOST', 'localhost');
    $dbname = qmeta_env('DB_NAME', 'lms');
    $user = qmeta_env('DB_USER', 'root');
    $pass = qmeta_env('DB_PASS', '');
    $port = qmeta_env('DB_PORT', 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Running migration: add question bank metadata...\n";

    // 1. New tables. The init file is the single source of truth for them.
    $pdo->exec((string) file_get_contents($schemaFile));
    echo "  -> taxonomy and version tables are in place\n";

    if (!qmeta_columns($pdo, 'questions')) {
        echo "The question bank is missing. Run create_question_bank.php first.\n";
        exit(1);
    }

    // 2. Additive columns. Keep this list in sync with the init files.
    $addColumns = [
        'questions' => [
            'category_id' => 'int(11) DEFAULT NULL AFTER is_archived',
            'subject_id' => 'int(11) DEFAULT NULL AFTER category_id',
            'topic_id' => 'int(11) DEFAULT NULL AFTER subject_id',
            'visibility' => "enum('private','institution') NOT NULL DEFAULT 'private' AFTER topic_id",
            'approval_status' => "enum('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft' AFTER visibility",
            'submitted_at' => 'datetime DEFAULT NULL AFTER approval_status',
            'reviewed_by' => 'int(11) DEFAULT NULL AFTER submitted_at',
            'reviewed_at' => 'datetime DEFAULT NULL AFTER reviewed_by',
            'review_note' => 'text DEFAULT NULL AFTER reviewed_at',
            'forked_from_id' => 'int(11) DEFAULT NULL AFTER review_note',
            'current_version_id' => 'int(11) DEFAULT NULL AFTER forked_from_id',
        ],
        'quiz_submission_questions' => [
            'question_version_id' => 'int(11) DEFAULT NULL AFTER question_snapshot',
        ],
    ];

    $added = 0;
    foreach ($addColumns as $table => $columns) {
        if (!qmeta_columns($pdo, $table)) {
            continue;
        }

        $existing = qmeta_columns($pdo, $table);
        foreach ($columns as $column => $definition) {
            if (isset($existing[$column])) {
                continue;
            }
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            $added++;
        }
    }
    echo "  -> columns added: {$added}\n";

    // 3. Grandfather legacy rows. A missing current_version_id means the row
    //    predates this migration (the application sets it on creation).
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT question_id, institution_id, question_text, image_question,
               question_type, points, difficulty, explanation, correct_answer
        FROM questions
        WHERE current_version_id IS NULL
        ORDER BY question_id ASC
    ");
    $stmt->execute();
    $legacy = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $optionStmt = $pdo->prepare("
        SELECT option_label, option_text, is_correct
        FROM question_bank_options
        WHERE question_id = :question_id
        ORDER BY option_label ASC
    ");
    $insertVersion = $pdo->prepare("
        INSERT INTO question_versions (
            question_id, institution_id, version_number, content_json, created_by
        ) VALUES (
            :question_id, :institution_id, 1, :content_json, NULL
        )
    ");
    $updateQuestion = $pdo->prepare("
        UPDATE questions
        SET current_version_id = :version_id,
            visibility = 'institution',
            approval_status = 'approved',
            reviewed_at = COALESCE(updated_at, NOW())
        WHERE question_id = :question_id
    ");

    $versioned = 0;
    foreach ($legacy as $question) {
        $questionId = (int) $question['question_id'];
        $optionStmt->execute(['question_id' => $questionId]);
        $options = [];
        foreach ($optionStmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $options[] = [
                'option_label' => (string) $option['option_label'],
                'option_text' => (string) $option['option_text'],
                'is_correct' => (int) $option['is_correct'],
            ];
        }

        $content = [
            'question_id' => $questionId,
            'question_text' => (string) $question['question_text'],
            'image_question' => $question['image_question'],
            'question_type' => (string) $question['question_type'],
            'points' => (int) $question['points'],
            'difficulty' => $question['difficulty'],
            'explanation' => $question['explanation'],
            'correct_answer' => $question['correct_answer'],
            'options' => $options,
        ];

        $insertVersion->execute([
            'question_id' => $questionId,
            'institution_id' => (int) $question['institution_id'],
            'content_json' => (string) json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);

        $versionId = (int) $pdo->lastInsertId();
        $updateQuestion->execute(['version_id' => $versionId, 'question_id' => $questionId]);
        $versioned++;
    }

    $pdo->commit();
    echo "  -> legacy questions versioned: {$versioned}\n";
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
