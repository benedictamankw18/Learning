<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\QuestionBankRepository;
use App\Repositories\QuizRepository;
use App\Utils\QuestionCsv;

/**
 * Phase 1C: question bank metadata, approval and immutable versions.
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
            skip('the Phase 1C metadata migration has not been applied');
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
    ], $fields);

    $stmt = $db->prepare("
        INSERT INTO questions (
            institution_id, owner_user_id, question_text, question_type, points,
            difficulty, explanation, correct_answer, visibility, approval_status,
            is_archived, category_id, subject_id, topic_id
        ) VALUES (
            :institution_id, :owner_user_id, :question_text, :question_type, :points,
            :difficulty, :explanation, :correct_answer, :visibility, :approval_status,
            :is_archived, :category_id, :subject_id, :topic_id
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

test('Phase 1C schema files and routes describe metadata, approval and versions', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $metadata = (string) file_get_contents($projectRoot . '/db/init/06-question-bank-metadata.sql');
    foreach (['question_categories', 'question_topics', 'question_versions'] as $table) {
        assertContains("CREATE TABLE IF NOT EXISTS `{$table}`", $metadata, "Metadata init must define {$table}");
    }
    assertContains('`subject_id` int(11) DEFAULT NULL', $metadata, 'Topics are subject-scoped');
    assertContains('`content_json` json NOT NULL', $metadata, 'Versions store immutable content');
    assertContains('uq_question_version_number', $metadata, 'Version numbers are unique per question');

    $bank = (string) file_get_contents($projectRoot . '/db/init/05-question-bank.sql');
    foreach (['visibility', 'approval_status', 'current_version_id', 'forked_from_id'] as $column) {
        assertContains("`{$column}`", $bank, "questions must define {$column}");
    }

    $lms = (string) file_get_contents($projectRoot . '/db/init/01-lms.sql');
    $shuffle = (string) file_get_contents($projectRoot . '/db/init/04-quiz-submission-questions.sql');
    assertContains('`question_version_id`', $lms, 'Fresh installs pin a version on attempts');
    assertContains('`question_version_id`', $shuffle, 'The shuffle init must also pin a version');

    $migration = (string) file_get_contents($root . '/migrations/add_question_bank_metadata.php');
    assertContains('06-question-bank-metadata.sql', $migration, 'The migration uses the init file as source of truth');
    assertContains('current_version_id', $migration);
    assertContains("visibility = 'institution'", $migration, 'Legacy rows are grandfathered as shared');

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    foreach ([
        'GET /question-bank/questions',
        'POST /question-bank/questions/{id}/submit-for-approval',
        'POST /question-bank/questions/{id}/approve',
        'POST /question-bank/questions/{id}/reject',
        'POST /question-bank/questions/{id}/fork',
        'GET /question-bank/questions/{id}/versions',
        'GET /question-bank/categories',
        'GET /question-bank/topics',
    ] as $route) {
        assertContains($route, $routes, "Route must exist: {$route}");
    }

    $repo = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('getServedQuestionContent', $repo, 'Attempts must resolve pinned version content');
    assertContains('question_version_id', $repo);
    assertContains('createVersion', $repo);

    $bankRepo = (string) file_get_contents($root . '/src/Repositories/QuestionBankRepository.php');
    foreach (['visibilityClause', 'createVersion', 'fork', 'submitForApproval', 'approve', 'reject'] as $method) {
        assertContains('function ' . $method, $bankRepo, "Bank repository must implement {$method}");
    }
});

test('Phase 1C metadata migration is additive, grandfathers legacy rows and is idempotent', function () {
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
        assertSame('institution', $rows[0]['visibility'], 'Legacy rows become institution-visible');
        assertSame('approved', $rows[0]['approval_status'], 'Legacy rows are grandfathered as approved');
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

test('Teachers see only their own and approved institution questions', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuestionBankRepository();
    $marker = 'visible ' . uniqid() . ' ';

    $db->beginTransaction();
    try {
        $rows = [
            ['owner_user_id' => 11, 'visibility' => 'private', 'approval_status' => 'draft'],
            ['owner_user_id' => 11, 'visibility' => 'institution', 'approval_status' => 'approved'],
            ['owner_user_id' => 99, 'visibility' => 'private', 'approval_status' => 'draft'],
            ['owner_user_id' => 99, 'visibility' => 'private', 'approval_status' => 'pending'],
            ['owner_user_id' => 99, 'visibility' => 'institution', 'approval_status' => 'approved'],
            ['owner_user_id' => 99, 'visibility' => 'private', 'approval_status' => 'rejected'],
            ['owner_user_id' => null, 'visibility' => 'private', 'approval_status' => 'draft'],
            ['owner_user_id' => null, 'visibility' => 'institution', 'approval_status' => 'approved'],
        ];
        $ids = [];
        foreach ($rows as $i => $row) {
            $ids[$i] = qmetaInsertQuestion($db, $row + [
                'institution_id' => 1,
                'question_text' => $marker . $i,
            ]);
        }
        $foreignId = qmetaInsertQuestion($db, [
            'institution_id' => 2,
            'owner_user_id' => null,
            'visibility' => 'institution',
            'approval_status' => 'approved',
            'question_text' => $marker . 'foreign',
        ]);

        $teacher = ['user_id' => 11, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
        $otherTeacher = ['user_id' => 99, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
        $admin = ['user_id' => 10, 'role' => 'admin', 'roles' => ['admin'], 'institution_id' => 1];
        $otherAdmin = ['user_id' => 20, 'role' => 'admin', 'roles' => ['admin'], 'institution_id' => 2];
        $super = ['user_id' => 1, 'role' => 'super_admin', 'roles' => ['super_admin'], 'is_super_admin' => 1, 'institution_id' => 0];

        $filters = ['q' => $marker];
        assertSame(4, $repo->countQuestions($filters, $teacher), 'Teacher sees own rows plus approved institution rows');
        assertSame(6, $repo->countQuestions($filters, $otherTeacher), 'The other teacher sees its own rows plus the approved set');
        assertSame(8, $repo->countQuestions($filters, $admin), 'Admin sees every question in the institution');
        assertSame(1, $repo->countQuestions($filters, $otherAdmin), 'Admin cannot see another institution');
        assertSame(9, $repo->countQuestions($filters, $super), 'Super admin sees all institutions');

        assertTrue(is_array($repo->findVisibleQuestion($ids[4], $teacher)), 'Approved institution row is visible');
        assertSame(null, $repo->findVisibleQuestion($ids[2], $teacher), 'Another teacher draft is hidden');
        assertTrue(is_array($repo->findVisibleQuestion($ids[2], $admin)), 'Admin can review a draft');
        assertSame(null, $repo->findVisibleQuestion($foreignId, $teacher), 'Cross-institution row is hidden');

        $teacherList = $repo->listQuestions($filters, $teacher, 50, 0);
        assertSame(4, count($teacherList), 'List and count use the same visibility rule');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Approval flow version edits and returns shared questions to pending', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $bankRepo = new QuestionBankRepository();

    $db->beginTransaction();
    try {
        $quizId = questionBankQuiz($repo, $pdo, '1C approval ' . uniqid());
        $text = '1C approval question ' . uniqid();
        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $text,
            'question_type' => 'short_answer',
            'points' => 2,
            'correct_answer' => 'First',
        ]);

        $row = qmetaQuestionRow($db, $questionId);
        assertSame('private', $row['visibility'], 'New questions start private');
        assertSame('draft', $row['approval_status'], 'New questions start as drafts');
        assertNotNull($row['current_version_id'], 'Version 1 is created on add');
        assertSame(1, qmetaVersionCount($db, $questionId));

        assertTrue($bankRepo->submitForApproval($questionId, 11));
        $row = qmetaQuestionRow($db, $questionId);
        assertSame('pending', $row['approval_status']);
        assertSame('private', $row['visibility']);

        assertTrue($bankRepo->approve($questionId, 10, 'Looks good'));
        $row = qmetaQuestionRow($db, $questionId);
        assertSame('approved', $row['approval_status']);
        assertSame('institution', $row['visibility']);
        assertSame(10, (int) $row['reviewed_by']);
        assertSame('Looks good', $row['review_note']);

        // A non-admin content edit creates a new version and returns the
        // institution copy to review.
        assertTrue($repo->updateQuestion($questionId, [
            'question_text' => $text . ' edited',
            'correct_answer' => 'Second',
            'actor_user_id' => 11,
            'reset_approval_on_edit' => true,
        ], $quizId));

        $row = qmetaQuestionRow($db, $questionId);
        assertSame('pending', $row['approval_status'], 'The edit must re-enter review');
        assertSame('private', $row['visibility'], 'The edit must stop being shared');
        assertSame(2, qmetaVersionCount($db, $questionId), 'The edit freezes version 2');
        $versionTwo = $db->prepare('SELECT version_id FROM question_versions WHERE question_id = :id AND version_number = 2');
        $versionTwo->execute(['id' => $questionId]);
        assertSame((int) $versionTwo->fetchColumn(), (int) $row['current_version_id'], 'The bank points at the newest version');

        // Admin edits keep approval and still create versions.
        assertTrue($bankRepo->approve($questionId, 10, null));
        assertTrue($repo->updateQuestion($questionId, [
            'explanation' => 'Admin clarification',
            'actor_user_id' => 10,
            'reset_approval_on_edit' => false,
        ], $quizId));
        $row = qmetaQuestionRow($db, $questionId);
        assertSame('approved', $row['approval_status'], 'Admin edits stay approved');
        assertSame(3, qmetaVersionCount($db, $questionId));
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Pinned versions drive serving, review and grading after a bank edit', function () {
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
            'actor_user_id' => 10,
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

test('Forking copies bank content into a new private question with its own versions', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $bankRepo = new QuestionBankRepository();

    $db->beginTransaction();
    try {
        $sourceId = qmetaInsertQuestion($db, [
            'institution_id' => 1,
            'owner_user_id' => 11,
            'question_text' => 'Fork source ' . uniqid(),
            'question_type' => 'multiple_choice',
            'points' => 3,
            'correct_answer' => 'B',
            'visibility' => 'institution',
            'approval_status' => 'approved',
        ]);
        $db->prepare("
            INSERT INTO question_bank_options (question_id, option_label, option_text, is_correct)
            VALUES (:id1, 'A', 'Wrong', 0), (:id2, 'B', 'Right', 1)
        ")->execute(['id1' => $sourceId, 'id2' => $sourceId]);
        $bankRepo->createVersion($sourceId, 11);

        $actor = ['user_id' => 99, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
        $forkId = $bankRepo->fork($sourceId, $actor);
        assertNotNull($forkId, 'Fork must return the new question ID');
        assertNotSame($sourceId, $forkId);

        $fork = qmetaQuestionRow($db, $forkId);
        assertSame(99, (int) $fork['owner_user_id'], 'The fork belongs to the copying teacher');
        assertSame('private', $fork['visibility']);
        assertSame('draft', $fork['approval_status']);
        assertSame($sourceId, (int) $fork['forked_from_id']);
        assertSame(3, (int) $fork['points'], 'Content is copied');
        assertSame(1, qmetaVersionCount($db, $forkId), 'The fork starts at version 1');

        $optionCount = $db->prepare('SELECT COUNT(*) FROM question_bank_options WHERE question_id = :id');
        $optionCount->execute(['id' => $forkId]);
        assertSame(2, (int) $optionCount->fetchColumn(), 'Options are copied');

        $source = qmetaQuestionRow($db, $sourceId);
        assertSame(11, (int) $source['owner_user_id'], 'The original owner is unchanged');
        assertSame('approved', $source['approval_status'], 'The original approval is unchanged');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Categories and topics are institution-scoped and guarded when in use', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $bankRepo = new QuestionBankRepository();

    $db->beginTransaction();
    try {
        $name = 'Category ' . uniqid();
        $categoryId = $bankRepo->createCategory(1, $name, null);
        assertTrue((int) $categoryId > 0);
        assertTrue(is_array($bankRepo->findCategory($categoryId, 1)));
        assertSame(null, $bankRepo->findCategory($categoryId, 2), 'Category cannot cross institutions');

        $topicName = 'Topic ' . uniqid();
        $topicId = $bankRepo->createTopic(1, null, $topicName);
        assertTrue((int) $topicId > 0);
        assertSame(null, $bankRepo->findTopic($topicId, 2), 'Topic cannot cross institutions');

        $list = $bankRepo->listTopics(1, null);
        assertTrue(count($list) >= 1);
        $found = false;
        foreach ($list as $topic) {
            if ((int) $topic['topic_id'] === $topicId) {
                $found = true;
            }
        }
        assertTrue($found, 'The new topic is listed');

        qmetaInsertQuestion($db, [
            'institution_id' => 1,
            'category_id' => $categoryId,
            'topic_id' => $topicId,
        ]);
        assertTrue($bankRepo->categoryInUse($categoryId, 1), 'Used category is detected');
        assertTrue($bankRepo->topicInUse($topicId, 1), 'Used topic is detected');

        $unusedCategory = $bankRepo->createCategory(1, 'Unused ' . uniqid(), null);
        assertTrue($bankRepo->deleteCategory($unusedCategory, 1));
        assertSame(null, $bankRepo->findCategory($unusedCategory, 1));
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('An existing bank question can be linked to a quiz without copying it', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();
    try {
        // Create the quiz inside the transaction so it rolls back with the
        // rest of the smoke data.
        $quiz = questionBankQuiz($repo, $pdo, '1C bank link ' . uniqid());
        $institutionId = qmetaInstitutionForQuiz($db, $quiz);
        assertTrue($institutionId > 0, 'The test quiz must have an institution');

        $questionId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => null,
            'visibility' => 'institution',
            'approval_status' => 'approved',
        ]);

        assertTrue($repo->linkExistingQuestion($quiz, $questionId), 'An approved bank row can be linked');
        $links = $db->prepare('SELECT COUNT(*) FROM quiz_question_links WHERE quiz_id = :quiz_id AND question_id = :question_id');
        $links->execute(['quiz_id' => $quiz, 'question_id' => $questionId]);
        assertSame(1, (int) $links->fetchColumn());
        assertSame(1, count($repo->getQuestions($quiz)), 'The quiz serves the linked bank row');

        assertFalse($repo->linkExistingQuestion($quiz, $questionId), 'Linking twice is rejected');

        $foreignId = qmetaInsertQuestion($db, [
            'institution_id' => $institutionId + 100,
            'visibility' => 'institution',
            'approval_status' => 'approved',
        ]);
        assertFalse($repo->linkExistingQuestion($quiz, $foreignId), 'Another institution cannot be linked');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('CSV import reuses approved bank rows but not hidden drafts', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    qmetaReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();
    try {
        // Create the quiz inside the transaction so it rolls back with the
        // rest of the smoke data.
        $quiz = questionBankQuiz($repo, $pdo, '1C CSV reuse ' . uniqid());
        $institutionId = qmetaInstitutionForQuiz($db, $quiz);
        assertTrue($institutionId > 0, 'The test quiz must have an institution');

        $approvedText = 'CSV approved ' . uniqid();
        $draftText = 'CSV draft ' . uniqid();

        qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => null,
            'question_text' => $approvedText,
            'visibility' => 'institution',
            'approval_status' => 'approved',
        ]);
        qmetaInsertQuestion($db, [
            'institution_id' => $institutionId,
            'owner_user_id' => null,
            'question_text' => $draftText,
            'visibility' => 'private',
            'approval_status' => 'draft',
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

        $approvedResult = $repo->importQuestions($quiz, $import($approvedText));
        assertSame(1, $approvedResult['imported'], 'An approved bank row must be linked');
        $approvedCount = $db->prepare('SELECT COUNT(*) FROM questions WHERE TRIM(question_text) = :text');
        $approvedCount->execute(['text' => $approvedText]);
        assertSame(1, (int) $approvedCount->fetchColumn(), 'No duplicate is created for approved text');

        $draftResult = $repo->importQuestions($quiz, $import($draftText));
        assertSame(1, $draftResult['imported'], 'A hidden draft must be imported');
        $draftCount = $db->prepare('SELECT COUNT(*) FROM questions WHERE TRIM(question_text) = :text');
        $draftCount->execute(['text' => $draftText]);
        assertSame(2, (int) $draftCount->fetchColumn(), 'A draft must not be reused');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
