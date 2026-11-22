<?php

// fixerMarker: 2026-11-22 quiz-share-export-results-bank-picker
declare(strict_types=1);

use App\Config\Database;
use App\Repositories\CourseSectionRepository;
use App\Repositories\QuestionBankRepository;
use App\Repositories\QuizRepository;
use App\Utils\QuestionCsv;

/**
 * Teacher-private question bank taxonomy, ownership and immutable versions.
 */

function qmetaReady(PDO $pdo): void
{
    foreach (['questions', 'question_bank_options', 'quiz_submission_questions'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('the central question bank has not been migrated');
        }
    }

    foreach (['question_categories', 'question_topics', 'question_versions'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('the question bank metadata migration has not been applied');
        }
    }
}

function qmetaTaxonomyReady(PDO $pdo): void
{
    qmetaReady($pdo);

    foreach (['question_categories', 'question_topics'] as $table) {
        if (!$pdo->query("SHOW COLUMNS FROM {$table} LIKE 'owner_user_id'")->fetch()) {
            skip('run migrations/scope_question_bank_to_teachers.php before testing taxonomy');
        }
    }
}

/**
 * Insert a bank row with controlled ownership/visibility for policy tests.
 *
 * @param array<string,mixed> $fields
 */
function qmetaInsertQuestion(PDO $db, array $fields = []): int
{
    $fields = array_merge([
        'institution_id' => 1,
        'owner_user_id' => null,
        'question_text' => 'qmeta ' . uniqid(),
        'question_type' => 'short_answer',
        'points' => 1,
        'difficulty' => 'medium',
        'explanation' => null,
        'correct_answer' => null,
        'visibility' => 'private',
        'approval_status' => 'draft',
        'is_archived' => 0,
        'category_id' => null,
        'subject_id' => null,
        'topic_id' => null,
        'course_section_id' => null,
    ], $fields);

    $stmt = $db->prepare("
        INSERT INTO questions (
            institution_id, owner_user_id, question_text, question_type, points,
            difficulty, explanation, correct_answer, visibility, approval_status,
            is_archived, category_id, subject_id, topic_id, course_section_id
        ) VALUES (
            :institution_id, :owner_user_id, :question_text, :question_type, :points,
            :difficulty, :explanation, :correct_answer, :visibility, :approval_status,
            :is_archived, :category_id, :subject_id, :topic_id, :course_section_id
        )
    ");
    $stmt->execute($fields);

    return (int) $db->lastInsertId();
}

function qmetaQuestionRow(PDO $db, int $questionId): array
{
    $stmt = $db->prepare('SELECT * FROM questions WHERE question_id = :id');
    $stmt->execute(['id' => $questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function qmetaVersionCount(PDO $db, int $questionId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM question_versions WHERE question_id = :id');
    $stmt->execute(['id' => $questionId]);

    return (int) $stmt->fetchColumn();
}

function qmetaInstitutionForQuiz(PDO $db, int $quizId): int
{
    $stmt = $db->prepare("
        SELECT cs.institution_id
        FROM quizzes q
        INNER JOIN class_subjects cs ON cs.course_id = q.course_id
        WHERE q.quiz_id = :id
    ");
    $stmt->execute(['id' => $quizId]);

    return (int) $stmt->fetchColumn();
}

test('Private-bank schema files and routes describe owner scope and versions', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $metadata = (string) file_get_contents($projectRoot . '/db/init/06-question-bank-metadata.sql');
    foreach (['question_categories', 'question_topics', 'question_versions'] as $table) {
        assertContains("CREATE TABLE IF NOT EXISTS `{$table}`", $metadata, "Metadata init must define {$table}");
    }
    assertContains('`owner_user_id` int(11) NOT NULL', $metadata, 'Taxonomy is owned by one teacher');
    assertContains('uq_qcategory_owner_name', $metadata, 'Section names are unique per owner');
    assertContains('uq_qtopic_owner_scope', $metadata, 'Topic names are unique per owner');
    assertContains('`subject_id` int(11) DEFAULT NULL', $metadata, 'Topics are subject-scoped');
    assertContains('`content_json` json NOT NULL', $metadata, 'Versions store immutable content');
    assertContains('uq_question_version_number', $metadata, 'Version numbers are unique per question');

    $bank = (string) file_get_contents($projectRoot . '/db/init/05-question-bank.sql');
    foreach (['owner_user_id', 'current_version_id', 'visibility', 'approval_status', 'forked_from_id'] as $column) {
        assertContains("`{$column}`", $bank, "questions must define {$column}");
    }
    assertContains('`course_section_id`', $bank, 'Bank questions can use lesson-plan Topic / Unit / Week buckets');
    assertContains('idx_questions_course_section', $bank, 'Course-section filtering is indexed');

    $sectionMigration = (string) file_get_contents($root . '/migrations/add_course_section_to_questions.php');
    assertContains('course_section_id', $sectionMigration);
    assertContains('idx_questions_course_section', $sectionMigration);

    $lms = (string) file_get_contents($projectRoot . '/db/init/01-lms.sql');
    $shuffle = (string) file_get_contents($projectRoot . '/db/init/04-quiz-submission-questions.sql');
    assertContains('`question_version_id`', $lms, 'Fresh installs pin a version on attempts');
    assertContains('`question_version_id`', $shuffle, 'The shuffle init must also pin a version');

    $migration = (string) file_get_contents($root . '/migrations/add_question_bank_metadata.php');
    assertContains('06-question-bank-metadata.sql', $migration, 'The migration uses the init file as source of truth');
    assertContains('current_version_id', $migration);
    assertContains("visibility = 'private'", $migration, 'Legacy rows do not become shared');
    assertContains("approval_status = 'draft'", $migration, 'Legacy rows do not enter an approval workflow');

    $scopeMigration = (string) file_get_contents($root . '/migrations/scope_question_bank_to_teachers.php');
    assertContains('fallbackOwnerId = 11', $scopeMigration, 'Legacy ownerless rows go to the designated teacher');
    assertContains('scope question bank to teacher-private ownership', $scopeMigration);

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    foreach ([
        'GET /question-bank/questions',
        'POST /question-bank/questions',
        'POST /question-bank/questions/import',
        'GET /question-bank/questions/export',
        'GET /question-bank/questions/import-template',
        'GET /question-bank/questions/{id}',
        'PUT /question-bank/questions/{id}',
        'POST /question-bank/questions/{id}/archive',
        'GET /question-bank/questions/{id}/versions',
        'GET /question-bank/categories',
        'GET /question-bank/topics',
    ] as $route) {
        assertContains($route, $routes, "Route must exist: {$route}");
    }
    foreach ([
        'POST /question-bank/questions/{id}/submit-for-approval',
        'POST /question-bank/questions/{id}/approve',
        'POST /question-bank/questions/{id}/reject',
        'POST /question-bank/questions/{id}/fork',
    ] as $route) {
        assertFalse(str_contains($routes, $route), "Removed route must be absent: {$route}");
    }

    $repo = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('getServedQuestionContent', $repo, 'Attempts must resolve pinned version content');
    assertContains('question_version_id', $repo);
    assertContains('createVersion', $repo);
    assertContains('function getQuestions(int $quizId, ?int $ownerUserId = null)', $repo);
    assertContains('function addQuestion(array $data)', $repo);
    assertContains('function linkExistingQuestion(int $quizId, int $questionId, int $ownerUserId)', $repo);
    assertContains('function importQuestions(int $quizId, array $rows, int $ownerUserId)', $repo);

    $bankRepo = (string) file_get_contents($root . '/src/Repositories/QuestionBankRepository.php');
    foreach (['ownerClause', 'findOwnedQuestion', 'createVersion'] as $method) {
        assertContains('function ' . $method, $bankRepo, "Bank repository must implement {$method}");
    }
    assertContains('function listCategories(int $institutionId, int $ownerId)', $bankRepo);
    assertContains('function listTopics(int $institutionId, ?int $subjectId, int $ownerId)', $bankRepo);
    assertContains('function setArchived(int $questionId, bool $archived, array $actor)', $bankRepo);
    foreach (['visibilityClause', 'findVisibleQuestion', 'fork', 'submitForApproval', 'approve', 'reject'] as $method) {
        assertFalse(str_contains($bankRepo, 'function ' . $method), "Removed bank method must be absent: {$method}");
    }

    $bankController = (string) file_get_contents($root . '/src/Controllers/QuestionBankController.php');
    assertContains('function requireBankTeacher', $bankController);
    assertContains("requireRole(['teacher'])", $bankController);

    $quizController = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    $authoringMethods = [
        'addQuestion',
        'linkExistingQuestion',
        'importQuestions',
        'exportQuestions',
        'importTemplate',
        'updateQuestion',
        'deleteQuestion',
        'findManageableQuiz',
    ];
    foreach ($authoringMethods as $method) {
        $body = testMethodBody($quizController, $method);
        assertNotSame('', $body, "Quiz authoring method {$method} must exist");
        assertFalse(
            str_contains($body, "requireRole(['admin'") || str_contains($body, "'super_admin'"),
            "Admins must not author quiz questions in {$method}"
        );
    }
    foreach (['addQuestion', 'linkExistingQuestion', 'findManageableQuiz', 'updateQuestion', 'deleteQuestion'] as $method) {
        assertContains(
            "requireRole(['teacher'])",
            testMethodBody($quizController, $method),
            "{$method} must be teacher-only"
        );
    }
});

test('Course-section migration is additive and idempotent', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    $name = 'lms_qsection_test_' . bin2hex(random_bytes(4));
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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $migration = dirname(__DIR__) . '/migrations/add_course_section_to_questions.php';
    $childEnv = [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $name,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
    ];

    try {
        $temp->exec("
            CREATE TABLE questions (
                question_id int(11) NOT NULL AUTO_INCREMENT,
                institution_id int(11) NOT NULL,
                owner_user_id int(11) DEFAULT NULL,
                question_text text NOT NULL,
                question_type varchar(50) NOT NULL,
                points int(11) NOT NULL DEFAULT 1,
                is_archived tinyint(1) NOT NULL DEFAULT 0,
                topic_id int(11) DEFAULT NULL,
                updated_at datetime DEFAULT current_timestamp(),
                PRIMARY KEY (question_id),
                KEY idx_questions_topic (topic_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $first = runPhpSnippet('require ' . var_export($migration, true) . ';', $childEnv);
        assertSame(0, $first['code'], 'The course-section migration must succeed: ' . $first['stdout'] . $first['stderr']);
        assertContains('added questions.course_section_id', $first['stdout']);
        assertContains('added idx_questions_course_section', $first['stdout']);

        $column = $temp->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'questions'
              AND COLUMN_NAME = 'course_section_id'
        ");
        $column->execute();
        assertSame(1, (int) $column->fetchColumn(), 'Questions must gain the course-section column');

        $index = $temp->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'questions'
              AND INDEX_NAME = 'idx_questions_course_section'
        ");
        $index->execute();
        assertSame(1, (int) $index->fetchColumn(), 'Course-section filtering must be indexed');

        $second = runPhpSnippet('require ' . var_export($migration, true) . ';', $childEnv);
        assertSame(0, $second['code'], 'The course-section migration must be re-runnable');
        assertContains('questions.course_section_id already exists', $second['stdout']);
        assertContains('idx_questions_course_section already exists', $second['stdout']);
    } finally {
        $temp = null;
        try {
            testDatabase()?->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable $e) {
            // Cleanup failure is not worth failing the suite.
        }
    }
});

test('Bank questions only use lesson-plan sections owned by the acting teacher', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    foreach (['course_sections', 'class_subjects', 'teachers', 'classes'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('lesson-plan course-section tables are not available');
        }
    }

    $column = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'questions'
          AND COLUMN_NAME = 'course_section_id'
    ");
    $column->execute();
    if ((int) $column->fetchColumn() !== 1) {
        skip('run migrations/add_course_section_to_questions.php before testing course sections');
    }

    $sectionRow = $pdo->query("
        SELECT cs.course_sections_id, cs.section_name, c.institution_id, t.user_id
        FROM course_sections cs
        INNER JOIN class_subjects c ON cs.course_id = c.course_id
        INNER JOIN teachers t ON c.teacher_id = t.teacher_id
        ORDER BY cs.course_sections_id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$sectionRow) {
        skip('no lesson-plan course sections are available');
    }

    $sectionId = (int) $sectionRow['course_sections_id'];
    $sectionName = (string) $sectionRow['section_name'];
    $ownerId = (int) $sectionRow['user_id'];
    $institutionId = (int) $sectionRow['institution_id'];

    $actor = [
        'user_id' => $ownerId,
        'role' => 'teacher',
        'roles' => ['teacher'],
        'institution_id' => $institutionId,
    ];
    $otherTeacher = $actor;
    $otherTeacher['user_id'] = $ownerId + 100000;
    $otherInstitution = $actor;
    $otherInstitution['institution_id'] = $institutionId + 1000;

    $sectionRepo = new CourseSectionRepository();
    assertTrue(
        is_array($sectionRepo->findOwnedForQuestionBank($sectionId, $institutionId, $ownerId)),
        'A teacher may use a lesson-plan section from their own course'
    );
    assertSame(
        null,
        $sectionRepo->findOwnedForQuestionBank($sectionId, $institutionId, $otherTeacher['user_id']),
        'Another teacher cannot use the section'
    );
    assertSame(
        null,
        $sectionRepo->findOwnedForQuestionBank($sectionId, $otherInstitution['institution_id'], $ownerId),
        'A section from another institution cannot be used'
    );

    $db = Database::getInstance()->getConnection();
    $repo = new QuestionBankRepository();
    $marker = 'course-section ' . uniqid() . ' ';

    $db->beginTransaction();
    try {
        $withSectionId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'question_text' => $marker . 'with-section',
            'course_section_id' => $sectionId,
        ]);
        $withoutSectionId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'question_text' => $marker . 'legacy',
        ]);

        $filters = ['q' => $marker];
        $sectionFilters = $filters;
        $sectionFilters['course_section_id'] = $sectionId;

        assertSame(1, $repo->countQuestions($sectionFilters, $actor), 'Course-section filter narrows the bank list');
        $list = $repo->listQuestions($sectionFilters, $actor, 50, 0);
        assertSame(1, count($list), 'List uses the same course-section filter');
        assertSame($sectionName, (string) $list[0]['course_section_name'], 'List returns the section name');

        $detail = $repo->findOwnedQuestion($withSectionId, $actor);
        assertTrue(is_array($detail), 'The owner can read the linked question');
        assertSame($sectionName, (string) $detail['course_section_name'], 'Detail returns the section name');
        assertSame($sectionId, (int) $detail['course_section_id'], 'Detail returns the section id');

        $legacy = $repo->findOwnedQuestion($withoutSectionId, $actor);
        assertTrue(is_array($legacy), 'Legacy rows without a section remain readable');
        assertTrue($legacy['course_section_id'] === null, 'Legacy rows keep a NULL course section');

        $export = $repo->questionsForExport($sectionFilters, $actor);
        assertSame(1, count($export), 'Export honours the course-section filter');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Private-bank metadata migration is additive, privatizes legacy rows and is idempotent', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    $name = 'lms_qmeta_test_' . bin2hex(random_bytes(4));
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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $migration = dirname(__DIR__) . '/migrations/add_question_bank_metadata.php';
    $childEnv = [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $name,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
    ];

    try {
        $temp->exec("
            CREATE TABLE questions (
                question_id int(11) NOT NULL AUTO_INCREMENT,
                institution_id int(11) NOT NULL,
                question_text text NOT NULL,
                image_question varchar(255) DEFAULT NULL,
                question_type varchar(50) NOT NULL,
                points int(11) DEFAULT 1,
                difficulty varchar(20) DEFAULT NULL,
                explanation text DEFAULT NULL,
                correct_answer varchar(500) DEFAULT NULL,
                is_archived tinyint(1) NOT NULL DEFAULT 0,
                updated_at datetime DEFAULT current_timestamp(),
                PRIMARY KEY (question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $temp->exec("
            CREATE TABLE question_bank_options (
                option_id int(11) NOT NULL AUTO_INCREMENT,
                question_id int(11) NOT NULL,
                option_label varchar(5) NOT NULL,
                option_text varchar(500) NOT NULL,
                is_correct tinyint(1) DEFAULT 0,
                PRIMARY KEY (option_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $temp->exec("
            CREATE TABLE quiz_submission_questions (
                submission_id int(11) NOT NULL,
                question_id int(11) NOT NULL,
                position int(11) NOT NULL,
                question_snapshot json DEFAULT NULL,
                PRIMARY KEY (submission_id, question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $temp->exec("
            INSERT INTO questions (question_id, institution_id, question_text, question_type, points, correct_answer)
            VALUES (7, 3, 'Legacy question', 'multiple_choice', 4, 'B'),
                   (8, 3, 'Second legacy', 'short_answer', 2, 'Yes')
        ");
        $temp->exec("
            INSERT INTO question_bank_options (question_id, option_label, option_text, is_correct)
            VALUES (7, 'A', 'Wrong', 0), (7, 'B', 'Right', 1)
        ");

        $first = runPhpSnippet('require ' . var_export($migration, true) . ';', $childEnv);
        assertSame(0, $first['code'], 'The migration must succeed: ' . $first['stdout'] . $first['stderr']);
        assertContains('columns added: 12', $first['stdout']);
        assertContains('legacy questions versioned: 2', $first['stdout']);

        foreach (['question_categories', 'question_topics', 'question_versions'] as $table) {
            assertTrue(testTableExists($temp, $table), "Migration must create {$table}");
        }

        $column = $temp->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'quiz_submission_questions'
              AND COLUMN_NAME = 'question_version_id'
        ");
        $column->execute();
        assertSame(1, (int) $column->fetchColumn(), 'Attempts must gain the version pin column');

        $rows = $temp->query('SELECT question_id, visibility, approval_status, current_version_id FROM questions ORDER BY question_id')->fetchAll();
        assertSame(2, count($rows));
        assertSame('private', $rows[0]['visibility'], 'Legacy rows stay private');
        assertSame('draft', $rows[0]['approval_status'], 'Legacy rows do not enter an approval workflow');
        assertNotNull($rows[0]['current_version_id'], 'Legacy rows point at version 1');
        assertTrue((int) $rows[0]['current_version_id'] > 0);

        $version = $temp->prepare('SELECT version_number, content_json FROM question_versions WHERE question_id = :id');
        $version->execute(['id' => 7]);
        $versionRow = $version->fetch(PDO::FETCH_ASSOC);
        assertSame(1, (int) $versionRow['version_number']);
        $content = json_decode((string) $versionRow['content_json'], true);
        assertTrue(is_array($content));
        assertSame('B', $content['correct_answer']);
        assertSame(2, count($content['options']), 'Options are frozen with the version');

        $version->execute(['id' => 8]);
        assertSame(1, (int) $version->fetch(PDO::FETCH_ASSOC)['version_number']);

        $second = runPhpSnippet('require ' . var_export($migration, true) . ';', $childEnv);
        assertSame(0, $second['code'], 'The migration must be re-runnable');
        assertContains('columns added: 0', $second['stdout']);
        assertContains('legacy questions versioned: 0', $second['stdout']);
        assertSame(2, (int) $temp->query('SELECT COUNT(*) FROM question_versions')->fetchColumn());
    } finally {
        $temp = null;
        try {
            testDatabase()?->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (Throwable $e) {
            // Cleanup failure is not worth failing the suite.
        }
    }
});

test('Teachers see only questions they own in their own institution', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuestionBankRepository();
    $marker = 'owned ' . uniqid() . ' ';

    $db->beginTransaction();
    try {
        $ids = [];
        foreach ([11, 11, 99, 99, 99, 99] as $i => $ownerId) {
            $ids[$i] = qmetaInsertQuestion($db, [
                'institution_id' => 1,
                'owner_user_id' => $ownerId,
                'question_text' => $marker . $i,
            ]);
        }
        $ownerlessId = qmetaInsertQuestion($db, [
            'institution_id' => 1,
            'owner_user_id' => null,
            'question_text' => $marker . 'ownerless',
        ]);
        $foreignId = qmetaInsertQuestion($db, [
            'institution_id' => 2,
            'owner_user_id' => 11,
            'question_text' => $marker . 'foreign',
        ]);

        $teacher = ['user_id' => 11, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
        $otherTeacher = ['user_id' => 99, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
        $admin = ['user_id' => 10, 'role' => 'admin', 'roles' => ['admin'], 'institution_id' => 1];
        $otherAdmin = ['user_id' => 20, 'role' => 'admin', 'roles' => ['admin'], 'institution_id' => 2];
        $super = ['user_id' => 1, 'role' => 'super_admin', 'roles' => ['super_admin'], 'is_super_admin' => 1, 'institution_id' => 0];

        $filters = ['q' => $marker];
        assertSame(2, $repo->countQuestions($filters, $teacher), 'A teacher sees only their own bank rows');
        assertSame(4, $repo->countQuestions($filters, $otherTeacher), 'Another teacher sees only their own rows');
        assertSame(0, $repo->countQuestions($filters, $admin), 'Admins have no bank visibility');
        assertSame(0, $repo->countQuestions($filters, $otherAdmin), 'Admins cannot cross tenant scope');
        assertSame(0, $repo->countQuestions($filters, $super), 'Super admins have no bank visibility');

        assertTrue(is_array($repo->findOwnedQuestion($ids[0], $teacher)), 'The owner can read their question');
        assertSame(null, $repo->findOwnedQuestion($ids[2], $teacher), 'Cross-teacher lookup returns null');
        assertSame(null, $repo->findOwnedQuestion($ownerlessId, $teacher), 'Ownerless rows are not part of a private bank');
        assertSame(null, $repo->findOwnedQuestion($foreignId, $teacher), 'Cross-institution rows are hidden');
        assertSame(null, $repo->findOwnedQuestion($ids[0], $admin), 'Admins cannot read bank questions');

        $teacherList = $repo->listQuestions($filters, $teacher, 50, 0);
        assertSame(2, count($teacherList), 'List and count use the same owner rule');

        $export = $repo->questionsForExport($filters, $teacher);
        assertSame(2, count($export), 'Export is limited to the teacher’s own rows');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Content edits create immutable versions without any approval workflow', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();
    try {
        $quizId = questionBankQuiz($repo, $pdo, 'private edit ' . uniqid());
        $text = 'private question ' . uniqid();
        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $text,
            'question_type' => 'short_answer',
            'points' => 2,
            'correct_answer' => 'First',
            'actor_user_id' => 11,
        ]);

        $row = qmetaQuestionRow($db, $questionId);
        assertSame(11, (int) $row['owner_user_id'], 'The author owns the private bank row');
        assertSame('private', $row['visibility'], 'Legacy columns stay private');
        assertSame('draft', $row['approval_status'], 'Legacy columns stay draft');
        assertNotNull($row['current_version_id'], 'Version 1 is created on add');
        assertSame(1, qmetaVersionCount($db, $questionId));

        assertTrue($repo->updateQuestion($questionId, [
            'question_text' => $text . ' edited',
            'correct_answer' => 'Second',
            'actor_user_id' => 11,
        ], $quizId));

        $row = qmetaQuestionRow($db, $questionId);
        assertSame(11, (int) $row['owner_user_id'], 'Editing cannot reassign ownership');
        assertSame('private', $row['visibility'], 'Editing does not start a sharing workflow');
        assertSame('draft', $row['approval_status'], 'Editing does not start an approval workflow');
        assertSame(2, qmetaVersionCount($db, $questionId), 'The edit freezes version 2');
        $versionTwo = $db->prepare('SELECT version_id FROM question_versions WHERE question_id = :id AND version_number = 2');
        $versionTwo->execute(['id' => $questionId]);
        assertSame((int) $versionTwo->fetchColumn(), (int) $row['current_version_id'], 'The bank points at the newest version');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Pinned versions drive serving and grading after a bank edit', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);
    if (!testTableExists($pdo, 'quiz_submission_answers')) {
        skip('submission answers table is not available');
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();
    try {
        $quizId = questionBankQuiz($repo, $pdo, '1C pinning ' . uniqid());
        $originalText = '1C pinned question ' . uniqid();

        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $originalText,
            'question_type' => 'short_answer',
            'points' => 2,
            'correct_answer' => 'Original',
            'explanation' => 'Original explanation',
            'actor_user_id' => 11,
        ]);

        // A per-quiz points override is captured in the snapshot but not in
        // the bank version.
        assertTrue($repo->updateQuestion($questionId, ['points' => 5], $quizId));

        $submissionId = 920000000 + random_int(1, 999999);
        $db->prepare("
            INSERT INTO quiz_submissions (submission_id, quiz_id, student_id, attempt, status)
            VALUES (:id, :quiz_id, 0, 1, 'in_progress')
        ")->execute(['id' => $submissionId, 'quiz_id' => $quizId]);

        $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);

        $pin = $db->prepare('SELECT question_version_id FROM quiz_submission_questions WHERE submission_id = :id');
        $pin->execute(['id' => $submissionId]);
        assertNotNull($pin->fetchColumn(), 'The attempt must pin a version');

        // Edit the bank after the attempt was served.
        // Only answerable content changes here. Points stay a per-quiz
        // override on the link, so the bank version keeps its original value.
        assertTrue($repo->updateQuestion($questionId, [
            'question_text' => $originalText . ' edited',
            'correct_answer' => 'Edited',
            'explanation' => 'Edited explanation',
            'actor_user_id' => 11,
        ], $quizId));
        assertSame(2, qmetaVersionCount($db, $questionId), 'The edit creates version 2');

        $served = $repo->getSubmissionQuestions($submissionId, $quizId, false);
        assertSame(1, count($served));
        assertSame($originalText, $served[0]['question_text'], 'Serving uses the pinned version');
        assertSame('Original', $served[0]['correct_answer'], 'The pinned answer key is preserved');
        assertSame('Original explanation', $served[0]['explanation']);
        assertSame(5, (int) $served[0]['points'], 'Served points come from the snapshot, not the version default');

        $fresh = $repo->getQuestions($quizId);
        assertSame($originalText . ' edited', $fresh[0]['question_text'], 'The quiz sees the bank edit');
        assertSame(5, (int) $fresh[0]['points'], 'The per-quiz override still wins');

        assertTrue($repo->submitQuiz($submissionId, [
            ['question_id' => $questionId, 'answer' => 'Original'],
        ], 0), 'Submission must succeed');

        $answerRow = $db->prepare('SELECT is_correct, points_earned FROM quiz_submission_answers WHERE submission_id = :id');
        $answerRow->execute(['id' => $submissionId]);
        $answer = $answerRow->fetch(PDO::FETCH_ASSOC);
        assertSame(1, (int) $answer['is_correct'], 'Grading uses the pinned answer key');
        assertSame(5, (int) $answer['points_earned'], 'Grading uses the served points');

        $submission = $db->prepare('SELECT score, max_score FROM quiz_submissions WHERE submission_id = :id');
        $submission->execute(['id' => $submissionId]);
        $score = $submission->fetch(PDO::FETCH_ASSOC);
        assertSame(5, (int) $score['score']);
        assertSame(5, (int) $score['max_score']);

        $details = $repo->getSubmissionAnswerDetails($submissionId);
        assertSame(1, count($details));
        assertSame($originalText, $details[0]['question_text'], 'Review uses the pinned version');
        assertSame('Original explanation', $details[0]['explanation']);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Wrong answers grade as zero under strict SQL mode', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);
    if (!testTableExists($pdo, 'quiz_submission_answers')) {
        skip('submission answers table is not available');
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    // Local MySQL may not enable strict mode; force it so a boolean bound as
    // an empty string is rejected the same way it is in the Docker database.
    $originalMode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $db->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES')");

    $db->beginTransaction();
    try {
        $quizId = questionBankQuiz($repo, $pdo, '1C wrong answer ' . uniqid());
        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => '1C strict grading ' . uniqid(),
            'question_type' => 'short_answer',
            'points' => 2,
            'correct_answer' => 'Right',
            'actor_user_id' => 11,
        ]);

        $submissionId = 920000000 + random_int(1, 999999);
        $db->prepare("
            INSERT INTO quiz_submissions (submission_id, quiz_id, student_id, attempt, status)
            VALUES (:id, :quiz_id, 0, 1, 'in_progress')
        ")->execute(['id' => $submissionId, 'quiz_id' => $quizId]);

        $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);

        assertTrue($repo->submitQuiz($submissionId, [
            ['question_id' => $questionId, 'answer' => 'Wrong'],
        ], 0), 'A wrong answer must still submit cleanly');

        $answerRow = $db->prepare('SELECT is_correct, points_earned FROM quiz_submission_answers WHERE submission_id = :id');
        $answerRow->execute(['id' => $submissionId]);
        $answer = $answerRow->fetch(PDO::FETCH_ASSOC);
        assertSame(0, (int) $answer['is_correct'], 'A wrong answer is stored as zero, not an empty string');
        assertSame(0, (int) $answer['points_earned'], 'A wrong answer earns no points');

        $submission = $db->prepare('SELECT score, max_score FROM quiz_submissions WHERE submission_id = :id');
        $submission->execute(['id' => $submissionId]);
        $score = $submission->fetch(PDO::FETCH_ASSOC);
        assertSame(0, (int) $score['score']);
        assertSame(2, (int) $score['max_score']);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->exec('SET SESSION sql_mode = ' . $db->quote($originalMode));
    }
});

test('Sections and topics are teacher-owned and guarded when in use', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaTaxonomyReady($pdo);

    $db = Database::getInstance()->getConnection();
    $bankRepo = new QuestionBankRepository();

    $db->beginTransaction();
    try {
        $sectionName = 'Section ' . uniqid();
        $sectionId = $bankRepo->createCategory(1, 11, $sectionName, null);
        assertTrue((int) $sectionId > 0);
        assertTrue(is_array($bankRepo->findCategory($sectionId, 1, 11)));
        assertSame(null, $bankRepo->findCategory($sectionId, 2, 11), 'Section cannot cross institutions');
        assertSame(null, $bankRepo->findCategory($sectionId, 1, 99), 'Section cannot cross teachers');

        $unitName = 'Unit ' . uniqid();
        $unitId = $bankRepo->createCategory(1, 11, $unitName, $sectionId);
        $unit = $bankRepo->findCategory($unitId, 1, 11);
        assertTrue(is_array($unit));
        assertSame($sectionId, (int) $unit['parent_id'], 'Unit/Week nests under a Section');

        $topicName = 'Topic ' . uniqid();
        $topicId = $bankRepo->createTopic(1, 11, null, $topicName);
        assertTrue((int) $topicId > 0);
        assertTrue(is_array($bankRepo->findTopic($topicId, 1, 11)));
        assertSame(null, $bankRepo->findTopic($topicId, 2, 11), 'Topic cannot cross institutions');
        assertSame(null, $bankRepo->findTopic($topicId, 1, 99), 'Topic cannot cross teachers');

        $list = $bankRepo->listTopics(1, null, 11);
        $found = false;
        foreach ($list as $topic) {
            if ((int) $topic['topic_id'] === $topicId) {
                $found = true;
            }
        }
        assertTrue($found, 'The teacher’s new topic is listed');
        assertSame([], $bankRepo->listTopics(1, null, 99), 'Another teacher has a separate taxonomy');

        qmetaInsertQuestion($db, [
            'institution_id' => 1,
            'owner_user_id' => 11,
            'category_id' => $unitId,
            'topic_id' => $topicId,
        ]);
        assertTrue($bankRepo->categoryInUse($unitId, 1, 11), 'Used section is detected');
        assertTrue($bankRepo->topicInUse($topicId, 1, 11), 'Used topic is detected');
        assertFalse($bankRepo->categoryInUse($unitId, 1, 99), 'Another teacher cannot use this section');

        $unusedSection = $bankRepo->createCategory(1, 11, 'Unused ' . uniqid(), null);
        assertTrue($bankRepo->deleteCategory($unusedSection, 1, 11));
        assertSame(null, $bankRepo->findCategory($unusedSection, 1, 11));
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Only the teacher’s own bank question can be linked to a quiz', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $ownerId = 11;

    $db->beginTransaction();
    try {
        // Create the quiz inside the transaction so it rolls back with the
        // rest of the smoke data.
        $quiz = questionBankQuiz($repo, $pdo, 'private bank link ' . uniqid());
        $institutionId = qmetaInstitutionForQuiz($db, $quiz);
        assertTrue($institutionId > 0, 'The test quiz must have an institution');

        $questionId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'question_text' => 'Owned bank link ' . uniqid(),
        ]);

        assertTrue($repo->linkExistingQuestion($quiz, $questionId, $ownerId), 'The owner can link their bank row');
        $links = $db->prepare('SELECT COUNT(*) FROM quiz_question_links WHERE quiz_id = :quiz_id AND question_id = :question_id');
        $links->execute(['quiz_id' => $quiz, 'question_id' => $questionId]);
        assertSame(1, (int) $links->fetchColumn());
        assertSame(1, count($repo->getQuestions($quiz, $ownerId)), 'The quiz serves the linked bank row');

        assertFalse($repo->linkExistingQuestion($quiz, $questionId, $ownerId), 'Linking twice is rejected');

        $otherId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => 99,
            'question_text' => 'Other teacher bank link ' . uniqid(),
        ]);
        assertFalse($repo->linkExistingQuestion($quiz, $otherId, $ownerId), 'Another teacher’s row cannot be linked');

        $ownerlessId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => null,
            'question_text' => 'Ownerless bank link ' . uniqid(),
        ]);
        assertFalse($repo->linkExistingQuestion($quiz, $ownerlessId, $ownerId), 'Ownerless rows cannot be linked');

        $foreignId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId + 100,
            'owner_user_id' => $ownerId,
            'question_text' => 'Foreign bank link ' . uniqid(),
        ]);
        assertFalse($repo->linkExistingQuestion($quiz, $foreignId, $ownerId), 'Another institution cannot be linked');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('CSV import reuses only questions in the same teacher’s private bank', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $ownerId = 11;

    $db->beginTransaction();
    try {
        // Create the quiz inside the transaction so it rolls back with the
        // rest of the smoke data.
        $quiz = questionBankQuiz($repo, $pdo, 'private CSV reuse ' . uniqid());
        $institutionId = qmetaInstitutionForQuiz($db, $quiz);
        assertTrue($institutionId > 0, 'The test quiz must have an institution');

        $ownedText = 'CSV owned ' . uniqid();
        $otherText = 'CSV other teacher ' . uniqid();
        $ownerlessText = 'CSV ownerless ' . uniqid();

        qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'question_text' => $ownedText,
        ]);
        qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => 99,
            'question_text' => $otherText,
        ]);
        qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => null,
            'question_text' => $ownerlessText,
        ]);

        $import = static function (string $text) use ($repo, $quiz): array {
            return [[
                'row' => 2,
                'question' => QuestionCsv::buildQuestion([
                    'question_text' => $text,
                    'question_type' => 'short_answer',
                    'correct_answer' => 'Anything',
                ]),
            ]];
        };
        $countText = static function (string $text) use ($db): int {
            $stmt = $db->prepare('SELECT COUNT(*) FROM questions WHERE TRIM(question_text) = :text');
            $stmt->execute(['text' => $text]);

            return (int) $stmt->fetchColumn();
        };

        $ownedResult = $repo->importQuestions($quiz, $import($ownedText), $ownerId);
        assertSame(1, $ownedResult['imported'], 'The teacher’s own bank row must be linked');
        assertSame(1, $countText($ownedText), 'No duplicate is created for owned text');

        $otherResult = $repo->importQuestions($quiz, $import($otherText), $ownerId);
        assertSame(1, $otherResult['imported'], 'A new row is imported');
        assertSame(2, $countText($otherText), 'Another teacher’s row must not be reused');

        $ownerlessResult = $repo->importQuestions($quiz, $import($ownerlessText), $ownerId);
        assertSame(1, $ownerlessResult['imported'], 'A new row is imported');
        assertSame(2, $countText($ownerlessText), 'An ownerless row must not be reused');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Review details carry the option labels and text the attempt was served', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);
    if (!testTableExists($pdo, 'quiz_submission_answers')) {
        skip('submission answers table is not available');
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();
    try {
        $quizId = questionBankQuiz($repo, $pdo, 'review options ' . uniqid());

        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => 'Which animal barks?',
            'question_type' => 'multiple_choice',
            'points' => 1,
            'correct_answer' => 'D',
            'options' => [
                ['label' => 'A', 'text' => 'Dog', 'is_correct' => 0],
                ['label' => 'B', 'text' => 'Lion', 'is_correct' => 0],
                ['label' => 'C', 'text' => 'Bird', 'is_correct' => 0],
                ['label' => 'D', 'text' => 'Cat', 'is_correct' => 1],
            ],
            'actor_user_id' => 11,
        ]);

        $submissionId = 930000000 + random_int(1, 999999);
        $db->prepare("
            INSERT INTO quiz_submissions (submission_id, quiz_id, student_id, attempt, status)
            VALUES (:id, :quiz_id, 0, 1, 'in_progress')
        ")->execute(['id' => $submissionId, 'quiz_id' => $quizId]);

        // This freezes the served question content, including its options.
        $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);

        // Change the bank options after the attempt was served. Review must
        // still show the wording the student actually saw.
        assertTrue($repo->updateQuestion($questionId, [
            'options' => [
                ['label' => 'A', 'text' => 'Changed', 'is_correct' => 0],
                ['label' => 'D', 'text' => 'Changed too', 'is_correct' => 1],
            ],
            'actor_user_id' => 11,
        ], $quizId));

        $db->prepare("
            INSERT INTO quiz_submission_answers
                (submission_id, question_id, answer, is_correct, points_earned)
            VALUES (:sid, :qid, 'A', 0, 0)
        ")->execute(['sid' => $submissionId, 'qid' => $questionId]);

        $details = $repo->getSubmissionAnswerDetails($submissionId);
        assertSame(1, count($details));

        $servedOptions = [];
        foreach (($details[0]['options'] ?? []) as $option) {
            $label = strtoupper(trim((string) ($option['option_label'] ?? '')));
            $servedOptions[$label] = (string) ($option['option_text'] ?? '');
        }

        assertSame('Dog', $servedOptions['A'] ?? null, 'Review keeps the served text for the chosen option');
        assertSame('Cat', $servedOptions['D'] ?? null, 'Review keeps the served text for the correct option');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
