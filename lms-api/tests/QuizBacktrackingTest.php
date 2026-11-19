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

    $studentJs = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');
    assertContains('preventBacktracking', $studentJs);
    assertContains(
        'S.take.preventBacktracking',
        $studentJs,
        'The student attempt must read the setting from the API'
    );
    assertContains(
        "if (!S.take || S.take.preventBacktracking) return;",
        $studentJs,
        'The previous button must refuse to move backwards, not just look disabled'
    );
    assertContains(
        'if (!btn || !S.take || S.take.preventBacktracking) return;',
        $studentJs,
        'The question grid must refuse to move backwards'
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
