<?php

// fixerMarker: 2026-11-23 quiz-results-csv-total-points
declare(strict_types=1);

test('Quiz results API exposes total points and percentage', function () {
    $root = dirname(__DIR__);

    $repository = (string) file_get_contents($root . '/src/Repositories/QuizRepository.php');
    assertContains('qs.max_score AS total_points', $repository);
    assertContains('END AS percentage', $repository);
});

test('Quiz results CSV uses total points fallback and computes percentage', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $js = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quiz-questions.js');
    assertContains('item.max_score', $js);
    assertContains('(scoreValue / totalValue * 100).toFixed(2)', $js);
    assertFalse(
        strpos($js, 'item.total_points || 0') !== false,
        'The CSV exporter must not hard-code missing total points to zero'
    );
});
