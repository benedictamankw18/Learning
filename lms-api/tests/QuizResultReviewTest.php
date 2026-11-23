<?php

// fixerMarker: 2026-11-23 truefalse-result-answer-label
declare(strict_types=1);

test('True/false result review maps A and B to true and false', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $js = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');

    assertContains('function formatReviewAnswer(answer, options, questionType)', $js);
    assertContains("=== 'true_false'", $js);
    assertContains('return normalizeReviewAnswer(raw, questionType);', $js);
    assertContains("(['a', 'true', '1', 't', 'yes'].includes(raw)) return 'true'", $js);
    assertContains("(['b', 'false', '0', 'f', 'no'].includes(raw)) return 'false'", $js);
});

test('Multiple choice result review still uses stored option labels', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $js = (string) file_get_contents($projectRoot . '/lms-frontend/student/js/quizzes.js');

    assertContains("return match ? label + '. ' + match.text : raw;", $js);
    assertContains('formatReviewAnswer(yourAnswer, reviewOptions, q.question_type)', $js);
    assertContains('formatReviewAnswer(correctAnswer, reviewOptions, q.question_type)', $js);
});
