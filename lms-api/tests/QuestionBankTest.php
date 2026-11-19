<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\QuizRepository;
use App\Utils\QuestionCsv;

/**
 * Create a disposable quiz on the first available course.
 */
function questionBankQuiz(QuizRepository $repo, PDO $pdo, string $title): int
{
    $courseId = (int) $pdo->query("SELECT course_id FROM class_subjects ORDER BY course_id ASC LIMIT 1")
        ->fetchColumn();

    if ($courseId <= 0) {
        skip('no course exists to attach a quiz to');
    }

    return $repo->create([
        'course_id' => $courseId,
        'title' => $title,
        'duration_minutes' => 10,
    ]);
}

function questionBankReady(PDO $pdo): void
{
    foreach (['questions', 'question_bank_options', 'quiz_question_links'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('the central question bank has not been migrated');
        }
    }
}

test('Question bank stores content once and applies a per-quiz points override', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    questionBankReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $quizId = questionBankQuiz($repo, $pdo, 'Bank override test ' . uniqid());
        $text = 'Bank override question ' . uniqid();

        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $text,
            'question_type' => 'multiple_choice',
            'points' => 2,
            'options' => [
                ['label' => 'A', 'text' => 'One', 'is_correct' => 1],
                ['label' => 'B', 'text' => 'Two', 'is_correct' => 0],
            ],
        ]);

        $bank = $db->prepare("SELECT * FROM questions WHERE question_id = :id");
        $bank->execute(['id' => $questionId]);
        $bankRow = $bank->fetch(PDO::FETCH_ASSOC);

        assertTrue(is_array($bankRow), 'The question must be stored in the bank');
        assertSame(2, (int) $bankRow['points'], 'The bank keeps the authored points');

        $options = $db->prepare("SELECT COUNT(*) FROM question_bank_options WHERE question_id = :id");
        $options->execute(['id' => $questionId]);
        assertSame(2, (int) $options->fetchColumn(), 'Options must live in the bank');

        $link = $db->prepare("
            SELECT position, points_override
            FROM quiz_question_links
            WHERE quiz_id = :quiz_id AND question_id = :question_id
        ");
        $link->execute(['quiz_id' => $quizId, 'question_id' => $questionId]);
        $linkRow = $link->fetch(PDO::FETCH_ASSOC);

        assertTrue(is_array($linkRow), 'The quiz must reference the bank row through a link');
        assertSame(0, (int) $linkRow['position'], 'The first question starts at position 0');
        assertSame(null, $linkRow['points_override'], 'New links inherit the bank points');

        // Editing points from a quiz must not rewrite the shared bank value.
        $repo->updateQuestion($questionId, ['points' => 5], $quizId);

        $questions = $repo->getQuestions($quizId);
        assertSame(5, (int) $questions[0]['points'], 'The quiz must use its points override');

        $bank->execute(['id' => $questionId]);
        assertSame(2, (int) $bank->fetch(PDO::FETCH_ASSOC)['points'], 'The bank default must stay unchanged');

        // A bank-level edit is what changes the shared default.
        $repo->updateQuestion($questionId, ['points' => 7]);
        $bank->execute(['id' => $questionId]);
        assertSame(7, (int) $bank->fetch(PDO::FETCH_ASSOC)['points'], 'A bank edit updates the default');
        assertSame(5, (int) $repo->getQuestions($quizId)[0]['points'], 'The override still wins');

        // Reordering through the quiz moves the link, not the bank row.
        $repo->updateQuestion($questionId, ['order_index' => 4], $quizId);
        assertSame(4, (int) $repo->getQuestions($quizId)[0]['order_index']);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Removing a question from a quiz only unlinks it from the bank', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    questionBankReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $quizId = questionBankQuiz($repo, $pdo, 'Bank unlink test ' . uniqid());

        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => 'Unlink me ' . uniqid(),
            'question_type' => 'short_answer',
            'points' => 1,
        ]);

        assertTrue($repo->deleteQuestion($questionId, $quizId), 'The link must be removed');

        $bank = $db->prepare("SELECT COUNT(*) FROM questions WHERE question_id = :id");
        $bank->execute(['id' => $questionId]);
        assertSame(1, (int) $bank->fetchColumn(), 'The bank row must survive an unlink');

        $links = $db->prepare("SELECT COUNT(*) FROM quiz_question_links WHERE question_id = :id");
        $links->execute(['id' => $questionId]);
        assertSame(0, (int) $links->fetchColumn(), 'No link may remain');

        assertSame([], $repo->getQuestions($quizId), 'The quiz must no longer show the question');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('A question is not found through a quiz it is not linked to', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    questionBankReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $owningQuiz = questionBankQuiz($repo, $pdo, 'Bank scope source ' . uniqid());
        $otherQuiz = questionBankQuiz($repo, $pdo, 'Bank scope other ' . uniqid());

        $questionId = $repo->addQuestion([
            'quiz_id' => $owningQuiz,
            'question_text' => 'Scoped question ' . uniqid(),
            'question_type' => 'short_answer',
            'points' => 1,
        ]);

        assertTrue(
            is_array($repo->findQuestionById($questionId, $owningQuiz)),
            'The owning quiz can resolve the question'
        );
        assertSame(
            null,
            $repo->findQuestionById($questionId, $otherQuiz),
            'A quiz that does not link the question must not resolve it'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Attempt snapshots keep the served content after a bank edit', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    questionBankReady($pdo);

    if (!testTableExists($pdo, 'quiz_submission_questions')) {
        skip('quiz_submission_questions table has not been migrated');
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $quizId = questionBankQuiz($repo, $pdo, 'Bank snapshot test ' . uniqid());
        $originalText = 'Snapshot original ' . uniqid();

        $questionId = $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => $originalText,
            'question_type' => 'short_answer',
            'points' => 3,
            'correct_answer' => 'Original answer',
            'explanation' => 'Original explanation',
        ]);

        $submissionId = 910000000 + random_int(1, 999999);
        $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);

        // Change the bank content after the attempt was served.
        $repo->updateQuestion($questionId, [
            'question_text' => 'Snapshot edited ' . uniqid(),
            'correct_answer' => 'Edited answer',
            'explanation' => 'Edited explanation',
        ], $quizId);

        $served = $repo->getSubmissionQuestions($submissionId, $quizId, false);
        assertSame(1, count($served));
        assertSame($originalText, $served[0]['question_text'], 'The attempt must keep its snapshot text');
        assertSame('Original answer', $served[0]['correct_answer'], 'The attempt must keep its snapshot answer key');
        assertSame(3, (int) $served[0]['points'], 'The attempt must keep its snapshot points');

        $fresh = $repo->getQuestions($quizId);
        assertNotSame($originalText, $fresh[0]['question_text'], 'The quiz itself sees the bank edit');

        $insert = $db->prepare("
            INSERT INTO quiz_submission_answers (
                submission_id, question_id, answer, is_correct, points_earned
            ) VALUES (
                :submission_id, :question_id, :answer, 1, 3
            )
        ");
        $insert->execute([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'answer' => 'Original answer',
        ]);

        $details = $repo->getSubmissionAnswerDetails($submissionId);
        assertSame(1, count($details));
        assertSame($originalText, $details[0]['question_text'], 'Review must use the attempt snapshot');
        assertSame('Original explanation', $details[0]['explanation'], 'Review must keep the snapshot explanation');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Quiz question endpoints scope bank edits to a quiz', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertTrue(
        strpos($repository, 'FROM quiz_questions') === false,
        'The repository must read the question bank instead of the legacy table'
    );
    assertContains('FROM quiz_question_links', $repository);
    assertContains('question_snapshot', $repository);

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains('resolveQuestionQuizId', $controller);
    assertContains('findQuestionById($id, $quizId)', $controller);
    assertContains('updateQuestion($id, $data, $quizId)', $controller);
    assertContains('deleteQuestion($id, $quizId)', $controller);
    assertContains('Question removed from quiz', $controller);

    $api = (string) file_get_contents($projectRoot . '/lms-frontend/assets/js/api.js');
    assertContains('updateQuestion: (id, data, quizId)', $api);
    assertContains('deleteQuestion: (id, quizId)', $api);
    assertContains('?quiz_id=', $api);

    $teacherUi = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quiz-questions.js');
    assertContains('QuizAPI.deleteQuestion(questionId, state.quizId)', $teacherUi);
});

test('CSV import reuses a matching bank row instead of duplicating it', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    questionBankReady($pdo);

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();

    $db->beginTransaction();

    try {
        $firstQuiz = questionBankQuiz($repo, $pdo, 'Bank reuse source ' . uniqid());
        $secondQuiz = questionBankQuiz($repo, $pdo, 'Bank reuse target ' . uniqid());
        $text = 'Reusable question ' . uniqid();

        $repo->addQuestion([
            'quiz_id' => $firstQuiz,
            'question_text' => $text,
            'question_type' => 'short_answer',
            'points' => 1,
        ]);

        $before = $db->prepare("SELECT COUNT(*) FROM questions WHERE TRIM(question_text) = :text");
        $before->execute(['text' => $text]);
        assertSame(1, (int) $before->fetchColumn());

        $prepared = [[
            'row' => 2,
            'question' => QuestionCsv::buildQuestion([
                'question_text' => $text,
                'question_type' => 'short_answer',
                'correct_answer' => 'Anything',
            ]),
        ]];

        $summary = $repo->importQuestions($secondQuiz, $prepared);
        assertSame(1, $summary['imported'], 'The existing bank question must be linked');
        assertSame(0, $summary['failed']);

        $after = $db->prepare("SELECT COUNT(*) FROM questions WHERE TRIM(question_text) = :text");
        $after->execute(['text' => $text]);
        assertSame(1, (int) $after->fetchColumn(), 'Import must not duplicate the bank row');

        $linked = $db->prepare("
            SELECT COUNT(*)
            FROM quiz_question_links l
            INNER JOIN questions qn ON qn.question_id = l.question_id
            WHERE l.quiz_id = :quiz_id AND TRIM(qn.question_text) = :text
        ");
        $linked->execute(['quiz_id' => $secondQuiz, 'text' => $text]);
        assertSame(1, (int) $linked->fetchColumn(), 'The question must be linked to the importing quiz');

        $again = $repo->importQuestions($secondQuiz, $prepared);
        assertSame(1, $again['skipped'], 'Re-importing the same quiz must skip the duplicate');
        assertSame(0, $again['imported']);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
