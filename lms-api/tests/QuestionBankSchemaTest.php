<?php

declare(strict_types=1);

/**
 * Phase 1B: central question bank.
 *
 * The migration is exercised against a disposable database so the backfill can
 * be checked without touching the configured schema. The legacy fixture
 * deliberately contains duplicate question text and an orphaned option to
 * prove the copy is faithful rather than a cleanup.
 */

/**
 * Connection settings for a child PHP process.
 *
 * @return array<string,string>
 */
function qbankChildEnv(string $database): array
{
    return [
        'DB_HOST' => (string) ($_ENV['DB_HOST'] ?? 'localhost'),
        'DB_PORT' => (string) ($_ENV['DB_PORT'] ?? '3306'),
        'DB_NAME' => $database,
        'DB_USER' => (string) ($_ENV['DB_USER'] ?? 'root'),
        'DB_PASS' => (string) ($_ENV['DB_PASS'] ?? ''),
    ];
}

/**
 * Run a migration file in a child process pointed at a disposable database.
 *
 * @return array{code:int,stdout:string,stderr:string}
 */
function qbankRunMigration(string $migrationFile, string $database): array
{
    return runPhpSnippet(
        'require ' . var_export($migrationFile, true) . ';',
        qbankChildEnv($database)
    );
}

/**
 * Create the minimal legacy schema the backfill reads from.
 */
function qbankCreateLegacyFixture(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE institutions (
            institution_id int(11) NOT NULL AUTO_INCREMENT,
            institution_name varchar(200) NOT NULL,
            PRIMARY KEY (institution_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE users (
            user_id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(100) NOT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE teachers (
            teacher_id int(11) NOT NULL AUTO_INCREMENT,
            institution_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            PRIMARY KEY (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE class_subjects (
            course_id int(11) NOT NULL AUTO_INCREMENT,
            institution_id int(11) NOT NULL,
            teacher_id int(11) DEFAULT NULL,
            PRIMARY KEY (course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE quizzes (
            quiz_id int(11) NOT NULL AUTO_INCREMENT,
            course_id int(11) NOT NULL,
            title varchar(200) NOT NULL,
            PRIMARY KEY (quiz_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE quiz_questions (
            question_id int(11) NOT NULL AUTO_INCREMENT,
            quiz_id int(11) NOT NULL,
            question_text text NOT NULL,
            image_question varchar(255) DEFAULT NULL,
            question_type varchar(50) NOT NULL,
            points int(11) DEFAULT 1,
            difficulty varchar(20) DEFAULT NULL,
            explanation text DEFAULT NULL,
            correct_answer varchar(500) DEFAULT NULL,
            order_index int(11) DEFAULT 0,
            created_at datetime DEFAULT current_timestamp(),
            updated_at datetime DEFAULT current_timestamp(),
            PRIMARY KEY (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE quiz_question_options (
            option_id int(11) NOT NULL AUTO_INCREMENT,
            question_id int(11) NOT NULL,
            option_label varchar(5) NOT NULL,
            option_text varchar(500) NOT NULL,
            is_correct tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT current_timestamp(),
            PRIMARY KEY (option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE quiz_submission_questions (
            submission_id int(11) NOT NULL,
            question_id int(11) NOT NULL,
            position int(11) NOT NULL,
            created_at datetime DEFAULT current_timestamp(),
            PRIMARY KEY (submission_id, question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("INSERT INTO institutions (institution_id, institution_name) VALUES (1, 'Alpha School'), (2, 'Beta School')");
    $pdo->exec("INSERT INTO users (user_id, username) VALUES (10, 'alpha.teacher'), (11, 'beta.teacher')");
    $pdo->exec("INSERT INTO teachers (teacher_id, institution_id, user_id) VALUES (5, 1, 10), (6, 2, 11)");
    $pdo->exec("INSERT INTO class_subjects (course_id, institution_id, teacher_id) VALUES (100, 1, 5), (101, 2, NULL)");
    $pdo->exec("INSERT INTO quizzes (quiz_id, course_id, title) VALUES (200, 100, 'Alpha quiz'), (201, 101, 'Beta quiz'), (202, 999, 'Course missing')");

    // Question 2 repeats question 1's text on purpose: no dedupe during the copy.
    $pdo->exec("
        INSERT INTO quiz_questions
            (question_id, quiz_id, question_text, question_type, points, difficulty, explanation, correct_answer, order_index)
        VALUES
            (1, 200, 'What is 2 + 2?', 'multiple_choice', 2, 'easy', 'Basic addition', 'B', 0),
            (2, 200, 'What is 2 + 2?', 'multiple_choice', 1, 'medium', NULL, 'B', 1),
            (3, 201, 'Name the capital of Ghana', 'short_answer', 5, 'hard', NULL, 'Accra', 0),
            (4, 202, 'Orphan course question', 'essay', 3, 'medium', NULL, NULL, 0)
    ");

    // Option 53 has no parent question and must be left behind.
    $pdo->exec("
        INSERT INTO quiz_question_options (option_id, question_id, option_label, option_text, is_correct)
        VALUES
            (50, 1, 'A', '3', 0),
            (51, 1, 'B', '4', 1),
            (52, 3, 'A', 'Accra', 1),
            (53, 999, 'A', 'Ghost option', 0)
    ");
}

/**
 * @return array{0:PDO,1:string}
 */
function qbankDisposableDatabase(): array
{
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    $name = 'lms_qbank_test_' . bin2hex(random_bytes(4));

    try {
        $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        skip('the configured database user cannot create a disposable database');
    }

    $host = (string) ($_ENV['DB_HOST'] ?? 'localhost');
    $port = (string) ($_ENV['DB_PORT'] ?? '3306');
    $user = (string) ($_ENV['DB_USER'] ?? 'root');
    $pass = (string) ($_ENV['DB_PASS'] ?? '');

    $temp = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return [$temp, $name];
}

test('Question bank init file and migration describe the same schema', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $init = (string) file_get_contents($projectRoot . '/db/init/05-question-bank.sql');

    foreach (['questions', 'question_bank_options', 'quiz_question_links'] as $table) {
        assertContains("CREATE TABLE IF NOT EXISTS `{$table}`", $init, "The init file must define {$table}");
    }

    foreach ([
        'institution_id',
        'owner_user_id',
        'question_text',
        'question_type',
        'points',
        'is_archived',
        'current_version_id',
    ] as $column) {
        assertContains("`{$column}`", $init, "questions must define {$column}");
    }

    assertContains('`points_override`', $init, 'Per-quiz points live on the link');
    assertContains('`position`', $init, 'Per-quiz order lives on the link');

    $metadataInit = (string) file_get_contents($projectRoot . '/db/init/06-question-bank-metadata.sql');
    assertContains('`owner_user_id` int(11) NOT NULL', $metadataInit, 'Taxonomy is teacher-owned');
    assertContains('uq_qcategory_owner_name', $metadataInit, 'Section names are unique per owner');
    assertContains('uq_qtopic_owner_scope', $metadataInit, 'Topic names are unique per owner');

    $scopeMigration = (string) file_get_contents($root . '/migrations/scope_question_bank_to_teachers.php');
    assertContains('scope_question_bank_to_teachers.php', $scopeMigration);
    assertContains('fallbackOwnerId = 11', $scopeMigration);

    $migration = (string) file_get_contents($root . '/migrations/create_question_bank.php');
    assertContains("db/init/05-question-bank.sql", $migration, 'The migration must use the init file as its source of truth');
    assertContains('INNER JOIN class_subjects', $migration, 'Institution ownership comes from the quiz course');
    assertContains('LEFT JOIN teachers', $migration, 'The owner is the course teacher, when there is one');

    // The snapshot column is part of Phase 1B and must exist for fresh installs.
    $canonical = (string) file_get_contents($projectRoot . '/db/init/01-lms.sql');
    assertContains('`question_snapshot` json', $canonical);
    $shuffle = (string) file_get_contents($projectRoot . '/db/init/04-quiz-submission-questions.sql');
    assertContains('`question_snapshot` json', $shuffle);
});

test('Question bank migration copies legacy questions losslessly', function () {
    [$pdo, $name] = qbankDisposableDatabase();
    $migration = dirname(__DIR__) . '/migrations/create_question_bank.php';

    try {
        qbankCreateLegacyFixture($pdo);

        $first = qbankRunMigration($migration, $name);
        assertSame(0, $first['code'], 'The migration must succeed: ' . $first['stdout'] . $first['stderr']);
        assertContains('questions copied: 3', $first['stdout']);
        assertContains('options copied:   3', $first['stdout']);
        assertContains('quiz links added: 3', $first['stdout']);
        assertContains('could not be linked', $first['stdout'], 'The skipped question must be reported');
        assertContains('have no matching question', $first['stdout'], 'The orphaned option must be reported');

        $ids = array_map('intval', $pdo->query('SELECT question_id FROM questions ORDER BY question_id')->fetchAll(PDO::FETCH_COLUMN));
        assertSame([1, 2, 3], $ids, 'Original question IDs must be preserved and the orphan skipped');

        $rows = $pdo->query('SELECT question_id, institution_id, owner_user_id, question_text, points FROM questions ORDER BY question_id')->fetchAll();
        assertSame(1, (int) $rows[0]['institution_id']);
        assertSame(10, (int) $rows[0]['owner_user_id'], 'The course teacher owns the question');
        assertSame(2, (int) $rows[0]['points']);

        assertSame('What is 2 + 2?', $rows[1]['question_text'], 'Duplicate text must be kept');
        assertSame(1, (int) $rows[1]['points']);

        assertSame(2, (int) $rows[2]['institution_id']);
        assertSame(null, $rows[2]['owner_user_id'], 'A course without a teacher yields an institution-owned question');
        assertSame(5, (int) $rows[2]['points']);

        $optionIds = array_map('intval', $pdo->query('SELECT option_id FROM question_bank_options ORDER BY option_id')->fetchAll(PDO::FETCH_COLUMN));
        assertSame([50, 51, 52], $optionIds, 'Option IDs must be preserved and the orphan skipped');
        assertSame(1, (int) $pdo->query('SELECT is_correct FROM question_bank_options WHERE option_id = 51')->fetchColumn());

        $links = $pdo->query('SELECT quiz_id, question_id, position, points_override FROM quiz_question_links ORDER BY quiz_id, position')->fetchAll();
        assertSame(3, count($links));
        assertSame(200, (int) $links[0]['quiz_id']);
        assertSame(1, (int) $links[0]['question_id']);
        assertSame(0, (int) $links[0]['position']);
        assertSame(null, $links[0]['points_override'], 'Legacy links rely on the bank points');
        assertSame(1, (int) $links[1]['position'], 'Authored order is preserved');
        assertSame(201, (int) $links[2]['quiz_id']);

        // Re-running must be a no-op.
        $second = qbankRunMigration($migration, $name);
        assertSame(0, $second['code'], 'The migration must be re-runnable');
        assertContains('questions copied: 0', $second['stdout']);
        assertContains('options copied:   0', $second['stdout']);
        assertContains('quiz links added: 0', $second['stdout']);

        assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn());
        assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM question_bank_options')->fetchColumn());
        assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM quiz_question_links')->fetchColumn());
    } finally {
        $pdo = null;
        try {
            testDatabase()?->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable $e) {
            // Leaving a disposable database behind is not worth failing the suite.
        }
    }
});

test('Question snapshot migration adds the column once', function () {
    [$pdo, $name] = qbankDisposableDatabase();
    $migration = dirname(__DIR__) . '/migrations/add_question_snapshot_to_quiz_submission_questions.php';

    try {
        qbankCreateLegacyFixture($pdo);

        $columnExists = function (PDO $pdo): bool {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'quiz_submission_questions'
                  AND COLUMN_NAME = 'question_snapshot'
            ");
            return (int) $stmt->fetchColumn() > 0;
        };

        assertFalse($columnExists($pdo), 'The fixture starts without the snapshot column');

        $first = qbankRunMigration($migration, $name);
        assertSame(0, $first['code'], 'The migration must succeed: ' . $first['stdout'] . $first['stderr']);
        assertTrue($columnExists($pdo), 'The snapshot column must be added');

        $second = qbankRunMigration($migration, $name);
        assertSame(0, $second['code']);
        assertContains('already exists', $second['stdout'], 'Re-running must be a no-op');
    } finally {
        $pdo = null;
        try {
            testDatabase()?->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable $e) {
            // Ignore cleanup failures.
        }
    }
});
