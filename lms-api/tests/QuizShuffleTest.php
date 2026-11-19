<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\QuizRepository;

/**
 * Pick a quiz with enough questions for shuffling to be observable.
 *
 * @return array{0:PDO,1:int,2:array<int,int>}
 */
function quizShuffleFixture(): array
{
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    if (!testTableExists($pdo, 'quiz_submission_questions')) {
        skip('quiz_submission_questions table has not been migrated');
    }

    $quiz = $pdo->query("
        SELECT q.quiz_id, COUNT(qq.question_id) AS question_count
        FROM quizzes q
        INNER JOIN quiz_questions qq ON qq.quiz_id = q.quiz_id
        GROUP BY q.quiz_id
        HAVING question_count >= 3
        ORDER BY question_count DESC, q.quiz_id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        skip('no quiz with at least three questions exists');
    }

    $quizId = (int) $quiz['quiz_id'];

    $stmt = $pdo->prepare("
        SELECT question_id
        FROM quiz_questions
        WHERE quiz_id = :quiz_id
        ORDER BY order_index ASC, created_at ASC, question_id ASC
    ");
    $stmt->execute(['quiz_id' => $quizId]);
    $authored = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return [$pdo, $quizId, $authored];
}

/**
 * Synthetic submission IDs that are not written to quiz_submissions. The
 * table has no foreign key, and every test rolls its rows back.
 *
 * @return int
 */
function quizShuffleSubmissionId(int $offset): int
{
    return 900000000 + $offset;
}

test('Quiz shuffle endpoint is routed and strips answer keys', function () {
    $root = dirname(__DIR__);

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    assertContains(
        "'GET /quiz-submissions/{id}/questions'",
        $routes,
        'The submission-scoped questions route must be registered'
    );

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains('public function getSubmissionQuestions', $controller);
    assertContains('$this->stripAnswerKeys($questions)', $controller);

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('ensureSubmissionQuestionOrder', $repository);
    assertContains('quiz_submission_questions', $repository);
});

test('Shuffled attempt order is a stable permutation persisted once', function () {
    [, $quizId, $authored] = quizShuffleFixture();

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $submissionId = quizShuffleSubmissionId(1);

    $db->beginTransaction();

    try {
        $order = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, true);

        assertSame(count($authored), count($order), 'Every question must appear exactly once');

        $sortedOrder = $order;
        $sortedAuthored = $authored;
        sort($sortedOrder);
        sort($sortedAuthored);
        assertSame($sortedAuthored, $sortedOrder, 'The order must be a permutation of the authored questions');

        // Resuming must never reshuffle, even if the quiz flag changed.
        $resumed = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);
        assertSame($order, $resumed, 'The persisted order must survive a resume');

        $stmt = $db->prepare("
            SELECT question_id, position
            FROM quiz_submission_questions
            WHERE submission_id = :submission_id
            ORDER BY position ASC
        ");
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        assertSame(count($authored), count($rows), 'One row per question must be persisted');
        assertSame($order, array_map(static fn ($row) => (int) $row['question_id'], $rows));
        assertSame(range(0, count($authored) - 1), array_map(static fn ($row) => (int) $row['position'], $rows));
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Shuffle off keeps the authored question order', function () {
    [, $quizId, $authored] = quizShuffleFixture();

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $submissionId = quizShuffleSubmissionId(2);

    $db->beginTransaction();

    try {
        $order = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, false);
        assertSame($authored, $order, 'With shuffle disabled the authored order must be used');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Different attempts get different question orders', function () {
    [, $quizId, $authored] = quizShuffleFixture();

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $authoredSignature = implode(',', $authored);
    $signatures = [];

    $db->beginTransaction();

    try {
        for ($i = 0; $i < 12; $i++) {
            $order = $repo->ensureSubmissionQuestionOrder(quizShuffleSubmissionId(100 + $i), $quizId, true);
            $signature = implode(',', $order);

            assertNotSame(
                $authoredSignature,
                $signature,
                'A shuffled attempt must not keep the authored order'
            );

            $signatures[] = $signature;
        }

        assertTrue(
            count(array_unique($signatures)) > 1,
            'Multiple attempts must not all receive the same order'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Attempt questions are served in the persisted order', function () {
    [, $quizId, $authored] = quizShuffleFixture();

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $submissionId = quizShuffleSubmissionId(3);

    $db->beginTransaction();

    try {
        $order = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, true);
        $questions = $repo->getSubmissionQuestions($submissionId, $quizId, false);

        assertSame(count($order), count($questions));
        assertSame($order, array_map(static fn ($q) => (int) $q['question_id'], $questions));
        assertTrue(isset($questions[0]['options']) && is_array($questions[0]['options']), 'Questions must include options');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Submission review follows the order the student saw', function () {
    [, $quizId, $authored] = quizShuffleFixture();

    $db = Database::getInstance()->getConnection();
    $repo = new QuizRepository();
    $submissionId = quizShuffleSubmissionId(4);

    $db->beginTransaction();

    try {
        $order = $repo->ensureSubmissionQuestionOrder($submissionId, $quizId, true);
        $first = $order[0];
        $second = $order[1];

        // Insert the answers in reverse order to prove the review query uses
        // the persisted position rather than insertion order.
        $insert = $db->prepare("
            INSERT INTO quiz_submission_answers (
                submission_id, question_id, answer, is_correct, points_earned
            ) VALUES (
                :submission_id, :question_id, :answer, 0, 0
            )
        ");
        $insert->execute([
            'submission_id' => $submissionId,
            'question_id' => $second,
            'answer' => 'review-test',
        ]);
        $insert->execute([
            'submission_id' => $submissionId,
            'question_id' => $first,
            'answer' => 'review-test',
        ]);

        $details = $repo->getSubmissionAnswerDetails($submissionId);
        assertSame(
            [$first, $second],
            array_map(static fn ($row) => (int) $row['question_id'], $details),
            'Review rows must follow the attempt order'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
