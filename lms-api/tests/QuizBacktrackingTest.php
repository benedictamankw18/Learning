<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\QuizRepository;
use App\Utils\Validator;

/**
 * The "Prevent Going Back" quiz setting.
 *
 * When enabled, a student attempt may only move forward: the previous button
 * stays disabled and the question grid stops being clickable.
 */
test('Prevent Going Back setting is wired through schema, API and UI', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $schema = (string) file_get_contents($projectRoot . '/db/init/01-lms.sql');
    assertContains('prevent_backtracking', $schema, 'The canonical schema must define the setting');

    assertTrue(
        file_exists($root . '/migrations/add_prevent_backtracking_to_quizzes.php'),
        'Existing databases need an additive migration'
    );

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains("'prevent_backtracking'", $repository, 'Create/update must persist the setting');

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains("->numeric('prevent_backtracking')", $controller);
    assertContains(
        "'prevent_backtracking' => !empty(\$quiz['prevent_backtracking']) ? 1 : 0",
        $controller,
        'The attempt questions endpoint must expose the setting to the student UI'
    );

    $teacherPage = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/page/quizzes.html');
    assertContains('quizPreventBacktracking', $teacherPage, 'Teachers need a control for the setting');

    $teacherJs = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quizzes.js');
    assertContains('quizPreventBacktracking', $teacherJs);
    assertContains('prevent_backtracking:', $teacherJs, 'The setting must be sent on save');

    assertContains(
        'furthest_position',
        $schema,
        'The canonical schema must track how far an attempt progressed'
    );

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    assertContains("GET /quiz-submissions/{id}/policy", $routes);
    assertContains("POST /quiz-submissions/{id}/progress", $routes);

    $studentJs = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');
    assertContains('preventBacktracking', $studentJs);
    assertContains(
        'getSubmissionPolicy',
        $studentJs,
        'The student UI must re-read the policy before navigating'
    );
    assertContains(
        'recordNavigation',
        $studentJs,
        'The student UI must report navigation progress to the server'
    );
    assertContains(
        "const disabled = locked ? ' disabled",
        $studentJs,
        'Locked question buttons must be rendered disabled'
    );
    assertContains(
        'Please answer this question before continuing.',
        $studentJs,
        'Locked navigation must require an answer before advancing'
    );
    assertContains(
        'E.prevBtn.disabled = Boolean(S.take.preventBacktracking)',
        $studentJs,
        'Previous must be disabled when Prevent Going Back is on'
    );

    $studentPage = (string) file_get_contents($projectRoot . '/lms-frontend/student/page/quizzes.html');
    assertContains('id="sqPrevQuestionBtn"', $studentPage);
    assertContains(
        '.sq-btn:disabled',
        $studentPage,
        'Disabled quiz buttons must be visibly distinct'
    );
});

test('Automatic draft saves stay silent while explicit save confirms', function () {
    $projectRoot = dirname(__DIR__, 2);
    $studentJs = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');

    assertContains('function saveDraft(notify = true)', $studentJs);
    assertContains(
        "if (notify) toast('Draft saved locally.', 'success');",
        $studentJs,
        'Only an explicit save should show the confirmation toast'
    );
    assertSame(
        2,
        substr_count($studentJs, 'saveDraft(false);'),
        'Navigation and modal close must save without toasting'
    );
    assertContains(
        "addEventListener('click', () => saveDraft())",
        $studentJs,
        'The Save Draft button must still confirm'
    );
});

test('Prevent Going Back column exists and defaults to off', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM quizzes LIKE 'prevent_backtracking'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        skip('run migrations/add_prevent_backtracking_to_quizzes.php');
    }

    assertSame('0', (string) $column['Default'], 'Existing quizzes must keep free navigation');
    assertSame('NO', strtoupper((string) $column['Null']), 'The column must be NOT NULL');
});

test('Prevent Going Back persists through quiz create and update', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM quizzes LIKE 'prevent_backtracking'");
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        skip('run migrations/add_prevent_backtracking_to_quizzes.php');
    }

    $courseId = (int) $pdo->query("SELECT course_id FROM class_subjects ORDER BY course_id ASC LIMIT 1")
        ->fetchColumn();

    if ($courseId <= 0) {
        skip('no course exists to attach a quiz to');
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $quizId = $repo->create([
            'course_id' => $courseId,
            'title' => 'Backtracking test quiz',
            'duration_minutes' => 10,
        ]);

        $created = $repo->findById($quizId);
        assertNotNull($created);
        assertSame(0, (int) $created['prevent_backtracking'], 'Omitting the setting must keep it off');

        assertTrue($repo->update($quizId, ['prevent_backtracking' => 1]));
        $locked = $repo->findById($quizId);
        assertSame(1, (int) $locked['prevent_backtracking'], 'The lock must persist');

        assertTrue($repo->update($quizId, ['prevent_backtracking' => 0]));
        $unlocked = $repo->findById($quizId);
        assertSame(0, (int) $unlocked['prevent_backtracking'], 'The lock must be switchable back off');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Prevent Going Back rejects non-numeric input', function () {
    $validator = new Validator(['prevent_backtracking' => 'yes']);
    $validator->numeric('prevent_backtracking');

    assertTrue($validator->fails(), 'A non-numeric value must be rejected');
    assertContains('prevent_backtracking', implode(' ', array_keys($validator->getErrors())));

    $valid = new Validator(['prevent_backtracking' => 1]);
    $valid->numeric('prevent_backtracking');
    assertFalse($valid->fails(), 'A numeric value must be accepted');
});

/**
 * Create a disposable in-progress attempt with a frozen question order.
 *
 * @return array{submission_id:int,question_ids:array<int,int>,quiz_id:int}
 */
function backtrackingAttempt(QuizRepository $repo, PDO $pdo, PDO $db, string $title, int $questionCount): array
{
    $quizId = questionBankQuiz($repo, $pdo, $title);

    for ($i = 1; $i <= $questionCount; $i++) {
        $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $title . ' question ' . $i,
            'question_type' => 'short_answer',
            'points' => 1,
            'correct_answer' => 'Answer ' . $i,
        ]);
    }

    $submissionId = 930000000 + random_int(1, 999999);
    $stmt = $db->prepare("
        INSERT INTO quiz_submissions (submission_id, quiz_id, student_id, attempt, status)
        VALUES (:id, :quiz_id, 0, 1, 'in_progress')
    ");
    $stmt->execute(['id' => $submissionId, 'quiz_id' => $quizId]);

    $questionIds = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);

    return [
        'submission_id' => $submissionId,
        'question_ids' => $questionIds,
        'quiz_id' => $quizId,
    ];
}

test('Locked progress is monotonic and captured answers cannot be overwritten', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    foreach (['quiz_submissions', 'quiz_submission_questions'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('run the quiz submission question migrations');
        }
    }
    if (!testTableExists($pdo, 'quizzes')) {
        skip('no quizzes table');
    }
    if (!$pdo->query("SHOW COLUMNS FROM quiz_submissions LIKE 'furthest_position'")->fetch()) {
        skip('run migrations/add_quiz_submission_progress.php');
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        $repo = new QuizRepository();
        $attempt = backtrackingAttempt($repo, $pdo, $db, 'Backtracking progress ' . uniqid(), 4);
        $submissionId = $attempt['submission_id'];

        $first = $repo->recordSubmissionProgress($submissionId, 0, 1, 'A', true);
        assertSame(null, $first['error']);
        assertSame(1, (int) $first['furthest_position']);

        $locks = $repo->getSubmissionAnswerLocks($submissionId);
        assertTrue($locks[0]['locked'], 'Leaving a question must capture its answer');
        assertSame('A', (string) $locks[0]['answer']);

        // A stale tab jumping ahead must not advance progress or rewrite Q1.
        $jump = $repo->recordSubmissionProgress($submissionId, 0, 3, 'Changed', true);
        assertNotNull($jump['error'], 'A locked attempt must not jump several questions ahead');

        $locks = $repo->getSubmissionAnswerLocks($submissionId);
        assertSame('A', (string) $locks[0]['answer'], 'The captured answer must never be overwritten');

        $furthest = $db->prepare('SELECT furthest_position FROM quiz_submissions WHERE submission_id = :id');
        $furthest->execute(['id' => $submissionId]);
        assertSame(1, (int) $furthest->fetchColumn());

        // Ordinary forward movement still works.
        $second = $repo->recordSubmissionProgress($submissionId, 1, 2, 'B', true);
        assertSame(null, $second['error']);
        assertSame(2, (int) $second['furthest_position']);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Locked navigation requires an answer before advancing', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    if (!testTableExists($pdo, 'quiz_submission_questions')
        || !$pdo->query("SHOW COLUMNS FROM quiz_submission_questions LIKE 'answer_locked'")->fetch()) {
        skip('run migrations/add_quiz_submission_progress.php');
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        $repo = new QuizRepository();
        $attempt = backtrackingAttempt($repo, $pdo, $db, 'Backtracking answer required ' . uniqid(), 3);
        $submissionId = $attempt['submission_id'];
        $ids = $attempt['question_ids'];

        $blank = $repo->recordSubmissionProgress($submissionId, 0, 1, '   ', true);
        assertNotNull($blank['error'], 'A blank answer must not allow the student to advance');
        assertSame(0, (int) $blank['furthest_position']);

        $locks = $repo->getSubmissionAnswerLocks($submissionId);
        assertFalse($locks[0]['locked'], 'A rejected move must not capture an answer');

        $answered = $repo->recordSubmissionProgress($submissionId, 0, 1, 'A', true);
        assertSame(null, $answered['error']);
        assertSame(1, (int) $answered['furthest_position']);

        // Simulate a blank capture written by an older client.
        $blankCapture = $db->prepare("
            UPDATE quiz_submission_questions
            SET locked_answer = ''
            WHERE submission_id = :submission_id AND question_id = :question_id
        ");
        $blankCapture->execute(['submission_id' => $submissionId, 'question_id' => $ids[0]]);

        assertNotNull(
            $repo->validateLockedAnswers(
                $submissionId,
                [['question_id' => $ids[0], 'answer' => '']],
                true
            ),
            'A blank captured answer must be rejected at submit'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Locked submission rejects changed and unreached answers', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    if (!testTableExists($pdo, 'quiz_submission_questions')
        || !$pdo->query("SHOW COLUMNS FROM quiz_submission_questions LIKE 'answer_locked'")->fetch()) {
        skip('run migrations/add_quiz_submission_progress.php');
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        $repo = new QuizRepository();
        $attempt = backtrackingAttempt($repo, $pdo, $db, 'Backtracking submit ' . uniqid(), 4);
        $submissionId = $attempt['submission_id'];
        $ids = $attempt['question_ids'];
        assertSame(4, count($ids));

        $repo->recordSubmissionProgress($submissionId, 0, 1, 'First', true);
        $repo->recordSubmissionProgress($submissionId, 1, 2, 'Second', true);

        $answers = [
            ['question_id' => $ids[0], 'answer' => 'First'],
            ['question_id' => $ids[1], 'answer' => '  second  '],
            ['question_id' => $ids[2], 'answer' => 'Third'],
        ];
        assertSame(null, $repo->validateLockedAnswers($submissionId, $answers, true));

        $changed = $answers;
        $changed[0]['answer'] = 'Changed after going back';
        assertNotNull(
            $repo->validateLockedAnswers($submissionId, $changed, true),
            'A changed earlier answer must be rejected'
        );
        assertSame(null,
            $repo->validateLockedAnswers($submissionId, $changed, false),
            'Unlocked quizzes must still allow answer changes'
        );

        $unreached = [['question_id' => $ids[3], 'answer' => 'Never seen']];
        assertNotNull(
            $repo->validateLockedAnswers($submissionId, $unreached, true),
            'An answer beyond the furthest reached question must be rejected'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Attempts started before progress tracking can catch up once', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    if (!testTableExists($pdo, 'quiz_submission_questions')
        || !$pdo->query("SHOW COLUMNS FROM quiz_submission_questions LIKE 'answer_locked'")->fetch()) {
        skip('run migrations/add_quiz_submission_progress.php');
    }

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        $repo = new QuizRepository();
        $attempt = backtrackingAttempt($repo, $pdo, $db, 'Backtracking legacy ' . uniqid(), 4);
        $submissionId = $attempt['submission_id'];
        $ids = $attempt['question_ids'];

        // No progress rows exist yet: this attempt predates the setting.
        assertSame(
            null,
            $repo->validateLockedAnswers(
                $submissionId,
                [['question_id' => $ids[3], 'answer' => 'Already seen']],
                true
            ),
            'Legacy attempts must not be blocked retroactively'
        );

        $catchUp = $repo->recordSubmissionProgress($submissionId, 3, 3, 'Already seen', true);
        assertSame(null, $catchUp['error'], 'A legacy attempt may report its current position');
        assertSame(3, (int) $catchUp['furthest_position']);

        $locks = $repo->getSubmissionAnswerLocks($submissionId);
        assertTrue($locks[3]['locked'], 'The catch-up answer must now be protected');
        assertSame('Already seen', (string) $locks[3]['answer']);

        $changed = [['question_id' => $ids[3], 'answer' => 'Changed after going back']];
        assertNotNull(
            $repo->validateLockedAnswers($submissionId, $changed, true),
            'Once caught up, going back must be rejected'
        );

        // Questions before the catch-up point have no captures to compare.
        assertSame(
            null,
            $repo->validateLockedAnswers(
                $submissionId,
                [['question_id' => $ids[0], 'answer' => 'First']],
                true
            )
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
