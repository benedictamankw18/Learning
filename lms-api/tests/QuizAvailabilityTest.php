<?php

declare(strict_types=1);

use App\Repositories\QuizRepository;

test('Quiz availability window includes both boundaries', function () {
    $repo = new QuizRepository();
    $quiz = [
        'start_date' => '2026-11-23 00:00:00',
        'end_date' => '2026-11-29 23:59:00',
    ];

    assertFalse(
        $repo->isQuizAvailableNow($quiz, new DateTime('2026-11-22 23:59:59')),
        'The quiz must not open before Available From'
    );
    assertTrue(
        $repo->isQuizAvailableNow($quiz, new DateTime('2026-11-23 00:00:00')),
        'Available From must be inclusive'
    );
    assertTrue(
        $repo->isQuizAvailableNow($quiz, new DateTime('2026-11-25 12:00:00'))
    );
    assertTrue(
        $repo->isQuizAvailableNow($quiz, new DateTime('2026-11-29 23:59:00')),
        'Available To must be inclusive'
    );
    assertFalse(
        $repo->isQuizAvailableNow($quiz, new DateTime('2026-11-29 23:59:01')),
        'The quiz must close after Available To'
    );
});

test('Quiz availability handles missing and invalid bounds', function () {
    $repo = new QuizRepository();

    assertTrue(
        $repo->isQuizAvailableNow(
            ['start_date' => null, 'end_date' => null],
            new DateTime('2026-11-19 23:20:00')
        ),
        'An unset window means always available'
    );
    assertTrue(
        $repo->isQuizAvailableNow(
            ['start_date' => '2026-11-23 00:00:00', 'end_date' => null],
            new DateTime('2026-12-01 00:00:00')
        ),
        'An unset end date means no upper bound'
    );
    assertTrue(
        $repo->isQuizAvailableNow(
            ['start_date' => null, 'end_date' => '2026-11-29 23:59:00'],
            new DateTime('2020-01-01 00:00:00')
        ),
        'An unset start date means no lower bound'
    );
    assertFalse(
        $repo->isQuizAvailableNow(
            ['start_date' => 'not a date', 'end_date' => null],
            new DateTime('2026-11-25 00:00:00')
        ),
        'An invalid bound must fail closed'
    );
});

test('Availability window is enforced on start and mirrored in the student UI', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('function isQuizAvailableNow', $repository);

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains('isQuizAvailableNow($quiz)', $controller);
    assertContains('This quiz is not available at this time.', $controller);

    $studentJs = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');
    assertContains('function isQuizWindowOpen', $studentJs);
    assertContains('Not Yet Available', $studentJs);
    assertContains("return 'Closed';", $studentJs);
});
