<?php

// fixerMarker: 2026-11-22 quiz-share-export-results-bank-picker
declare(strict_types=1);

test('quiz sharing schema and routes are declared', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $sql = (string) file_get_contents($projectRoot . '/db/init/01-lms.sql');
    assertContains('CREATE TABLE IF NOT EXISTS `quiz_shares`', $sql);
    assertContains('PRIMARY KEY (`quiz_id`,`course_id`)', $sql);
    assertContains('idx_quiz_shares_course_id', $sql);

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    assertContains('GET /quizzes/{id}/shares', $routes);
    assertContains('PUT /quizzes/{id}/shares', $routes);
});

test('quiz sharing stays owner scoped and shared-aware', function () {
    $root = dirname(__DIR__);

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains('function canManageQuiz', $controller);
    assertContains("(int) (\$quiz['teacher_id'] ?? 0) === (int) (\$user['teacher_id'] ?? 0)", $controller);
    assertContains('You can only share with classes assigned to this quiz owner.', $controller);
    assertContains('function studentCanAccessQuiz', $controller);
    assertContains('function getShareNotificationCourses', $controller);

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('function getSharedCourseIds', $repository);
    assertContains('function getAllowedCourseIds', $repository);
    assertContains('function replaceShares', $repository);
    assertContains('qs.course_id = :share_course_id', $repository);
});

test('quiz details exposes the share popup and API helpers', function () {
    $projectRoot = dirname(dirname(__DIR__));

    $config = (string) file_get_contents($projectRoot . '/lms-frontend/assets/js/config.js');
    assertContains('QUIZ_SHARES', $config);

    $api = (string) file_get_contents($projectRoot . '/lms-frontend/assets/js/api.js');
    assertContains('getShares: (id)', $api);
    assertContains('saveShares: (id, data)', $api);

    $html = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/page/quiz-details.html');
    assertContains('id="quizShareBtn"', $html);
    assertContains('id="quizShareModal"', $html);
    assertContains('Save Sharing', $html);

    $js = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quiz-details.js');
    assertContains('function openShareModal', $js);
    assertContains('function loadShareData', $js);
    assertContains('function saveShare', $js);
});
