<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\QuestionBankRepository;
use App\Repositories\QuizRepository;
use App\Utils\QuestionCsv;

/**
 * Build one CSV line using the canonical column order.
 */
function questionCsvLine(array $overrides = []): string
{
    $row = array_fill_keys(QuestionCsv::header(), '');

    foreach ($overrides as $key => $value) {
        $row[$key] = $value;
    }

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array_values($row), ',', '"', '');
    rewind($handle);
    $line = stream_get_contents($handle);
    fclose($handle);

    return rtrim((string) $line, "\r\n");
}

/**
 * @param array<int,array<string,string>> $rows
 */
function questionCsvDocument(array $rows): string
{
    $lines = [implode(',', QuestionCsv::header())];

    foreach ($rows as $row) {
        $lines[] = questionCsvLine($row);
    }

    return implode("\r\n", $lines) . "\r\n";
}

test('Question CSV parses comments, BOM, quoted commas, quotes and newlines', function () {
    $csv = "\xEF\xBB\xBF# hint line\n\n" . questionCsvDocument([
        [
            'question_text' => "What is 2, 2?\nExplain your answer.",
            'question_type' => 'multiple_choice',
            'difficulty' => 'easy',
            'points' => '2',
            'option_a' => 'A "quoted" option',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_answer' => 'A',
            'explanation' => 'Because, reasons',
        ],
    ]);

    $parsed = QuestionCsv::parse($csv);

    assertSame(null, $parsed['error']);
    assertSame(1, count($parsed['rows']));

    $data = $parsed['rows'][0]['data'];
    assertSame("What is 2, 2?\nExplain your answer.", $data['question_text']);
    assertSame('A "quoted" option', $data['option_a']);
    assertSame('Because, reasons', $data['explanation']);
});

test('Question CSV rejects a file without the required headers', function () {
    $parsed = QuestionCsv::parse("question_text\r\nOnly text\r\n");

    assertNotNull($parsed['error']);
    assertContains('question_type', (string) $parsed['error']);
    assertSame([], $parsed['rows']);
});

test('Question CSV builds every supported question type', function () {
    $mcq = QuestionCsv::buildQuestion([
        'question_text' => 'Which one?',
        'question_type' => 'multiple_choice',
        'difficulty' => 'hard',
        'points' => '3',
        'option_a' => 'First',
        'option_b' => 'Second',
        'option_c' => 'Third',
        'correct_answer' => 'b',
    ]);

    assertSame('multiple_choice', $mcq['question_type']);
    assertSame(3, $mcq['points']);
    assertSame('hard', $mcq['difficulty']);
    assertSame('B', $mcq['correct_answer']);
    assertSame(3, count($mcq['options']));
    assertSame(1, $mcq['options'][1]['is_correct']);
    assertSame(0, $mcq['options'][0]['is_correct']);

    $trueFalse = QuestionCsv::buildQuestion([
        'question_text' => 'The sky is blue.',
        'question_type' => 'true_false',
        'correct_answer' => 'yes',
    ]);

    assertSame('TRUE', $trueFalse['correct_answer']);
    assertSame([], $trueFalse['options']);

    $shortAnswer = QuestionCsv::buildQuestion([
        'question_text' => 'Name an input device.',
        'question_type' => 'short_answer',
    ]);

    assertSame(null, $shortAnswer['correct_answer']);
    assertSame(1, $shortAnswer['points']);
    assertSame('medium', $shortAnswer['difficulty']);

    $essay = QuestionCsv::buildQuestion([
        'question_text' => 'Explain the water cycle.',
        'question_type' => 'essay',
        'correct_answer' => 'Evaporation, condensation, precipitation.',
    ]);

    assertSame('Evaporation, condensation, precipitation.', $essay['correct_answer']);
});

test('Question CSV rejects invalid rows with useful messages', function () {
    assertThrows(
        fn () => QuestionCsv::buildQuestion(['question_text' => 'x', 'question_type' => 'matching']),
        InvalidArgumentException::class,
        'question_type'
    );

    assertThrows(
        fn () => QuestionCsv::buildQuestion([
            'question_text' => 'x',
            'question_type' => 'multiple_choice',
            'option_a' => 'Only option',
            'correct_answer' => 'A',
        ]),
        InvalidArgumentException::class,
        'at least two options'
    );

    assertThrows(
        fn () => QuestionCsv::buildQuestion([
            'question_text' => 'x',
            'question_type' => 'multiple_choice',
            'option_a' => 'A',
            'option_b' => 'B',
            'correct_answer' => 'E',
        ]),
        InvalidArgumentException::class,
        'correct_answer'
    );

    assertThrows(
        fn () => QuestionCsv::buildQuestion([
            'question_text' => 'x',
            'question_type' => 'short_answer',
            'points' => '0',
        ]),
        InvalidArgumentException::class,
        'points'
    );

    assertThrows(
        fn () => QuestionCsv::buildQuestion([
            'question_text' => 'x',
            'question_type' => 'true_false',
            'correct_answer' => 'maybe',
        ]),
        InvalidArgumentException::class,
        'TRUE or FALSE'
    );
});

test('Question CSV template is importable and export round-trips', function () {
    $parsed = QuestionCsv::parse(QuestionCsv::template());

    assertSame(null, $parsed['error']);
    assertSame(3, count($parsed['rows']));

    foreach ($parsed['rows'] as $row) {
        QuestionCsv::buildQuestion($row['data']);
    }

    $questions = [[
        'question_text' => "Line one\nLine two, with comma",
        'question_type' => 'multiple_choice',
        'difficulty' => 'hard',
        'points' => 3,
        'correct_answer' => 'B',
        'explanation' => '=1+1 is not executed',
        'image_question' => '/api/upload/quiz-questions/diagram.png',
        'options' => [
            ['option_label' => 'A', 'option_text' => 'First', 'is_correct' => 0],
            ['option_label' => 'B', 'option_text' => 'Second, "quoted"', 'is_correct' => 1],
        ],
    ]];

    $csv = QuestionCsv::serialize($questions);
    assertContains("'=1+1", $csv, 'Formula-looking cells must be neutralized on export');

    $parsed = QuestionCsv::parse("\xEF\xBB\xBF" . $csv);
    assertSame(null, $parsed['error']);

    $built = QuestionCsv::buildQuestion($parsed['rows'][0]['data']);
    assertSame($questions[0]['question_text'], $built['question_text']);
    assertSame('Second, "quoted"', $built['options'][1]['text']);
    assertSame('=1+1 is not executed', $built['explanation']);
    assertSame('/api/upload/quiz-questions/diagram.png', $built['image_question']);
});

test('Question import appends, skips duplicates and continues after bad rows', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
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
            'title' => 'Question CSV import test',
            'duration_minutes' => 10,
        ]);

        $repo->addQuestion([
            'quiz_id' => $quizId,
            'question_text' => 'Existing question',
            'question_type' => 'short_answer',
            'order_index' => 0,
        ]);

        $prepared = [
            ['row' => 2, 'question' => QuestionCsv::buildQuestion([
                'question_text' => 'New question one',
                'question_type' => 'short_answer',
                'correct_answer' => 'One',
            ])],
            ['row' => 3, 'question' => QuestionCsv::buildQuestion([
                'question_text' => 'Existing question',
                'question_type' => 'short_answer',
            ])],
            ['row' => 4, 'question' => QuestionCsv::buildQuestion([
                'question_text' => 'New question one',
                'question_type' => 'short_answer',
            ])],
            ['row' => 5, 'question' => QuestionCsv::buildQuestion([
                'question_text' => 'New question two',
                'question_type' => 'true_false',
                'correct_answer' => 'false',
            ])],
        ];

        $summary = $repo->importQuestions($quizId, $prepared);

        assertSame(2, $summary['imported']);
        assertSame(2, $summary['skipped']);
        assertSame(0, $summary['failed']);

        $questions = $repo->getQuestions($quizId);

        assertSame(3, count($questions));
        assertSame(
            [0, 1, 2],
            array_map(static fn ($question) => (int) $question['order_index'], $questions),
            'Imported questions must append after the existing order'
        );
        assertSame(
            ['Existing question', 'New question one', 'New question two'],
            array_map(static fn ($question) => (string) $question['question_text'], $questions)
        );

        $parsed = QuestionCsv::parse(QuestionCsv::serialize($questions));

        assertSame(null, $parsed['error']);
        assertSame(3, count($parsed['rows']));

        foreach ($parsed['rows'] as $row) {
            QuestionCsv::buildQuestion($row['data']);
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('Question import and export endpoints are routed and wired into the teacher UI', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $routes = (string) file_get_contents($root . '/src/Routes/api.php');
    assertContains("'POST /quizzes/{id}/questions/import'", $routes);
    assertContains("'GET /quizzes/{id}/questions/export'", $routes);
    assertContains("'GET /quizzes/{id}/questions/import-template'", $routes);
    assertContains("'POST /question-bank/questions/import'", $routes);
    assertContains("'GET /question-bank/questions/export'", $routes);
    assertContains("'GET /question-bank/questions/import-template'", $routes);

    $controller = (string) file_get_contents($root . '/src/Controllers/QuizController.php');
    assertContains('public function importQuestions', $controller);
    assertContains('public function exportQuestions', $controller);
    assertContains('public function importTemplate', $controller);
    assertContains('QuestionCsv::parse', $controller);
    assertContains('QuestionCsv::serialize', $controller);
    assertContains('QuestionCsv::template', $controller);

    $bankController = (string) file_get_contents($root . '/src/Controllers/QuestionBankController.php');
    assertContains('public function importQuestions', $bankController);
    assertContains('public function exportQuestions', $bankController);
    assertContains('public function importTemplate', $bankController);
    assertContains('QuestionCsv::parse', $bankController);
    assertContains('QuestionCsv::serialize', $bankController);
    assertContains('QuestionCsv::template', $bankController);

    $config = (string) file_get_contents($projectRoot . '/lms-frontend/assets/js/config.js');
    assertContains('QUIZ_QUESTION_IMPORT', $config);
    assertContains('QUIZ_QUESTION_EXPORT', $config);
    assertContains('QUIZ_QUESTION_IMPORT_TEMPLATE', $config);
    assertContains('QUESTION_BANK_QUESTION_IMPORT', $config);
    assertContains('QUESTION_BANK_QUESTION_EXPORT', $config);
    assertContains('QUESTION_BANK_QUESTION_IMPORT_TEMPLATE', $config);

    $api = (string) file_get_contents($projectRoot . '/lms-frontend/assets/js/api.js');
    assertContains('importQuestions:', $api);
    assertContains('exportQuestions:', $api);
    assertContains('downloadImportTemplate:', $api);
    assertContains('API_ENDPOINTS.QUESTION_BANK_QUESTION_IMPORT', $api);

    // The original modal in quizzes.html is unreachable; the controls that
    // matter live on the standalone quiz-questions and question-bank pages.
    $oldPage = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/page/quizzes.html');
    assertContains('questionImportBtn', $oldPage);
    assertContains('questionExportBtn', $oldPage);
    assertContains('quizQuestionImportModal', $oldPage);

    $oldJs = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quizzes.js');
    assertContains('importQuestionsCsv', $oldJs);
    assertContains('exportQuestionsCsv', $oldJs);
    assertContains('downloadQuestionImportTemplate', $oldJs);

    $quizPage = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/page/quiz-questions.html');
    assertContains('quizQuestionsImportBtn', $quizPage);
    assertContains('quizQuestionsExportQuestionsBtn', $quizPage);
    assertContains('quizQuestionsTemplateBtn', $quizPage);
    assertContains('quizQuestionsImportModal', $quizPage);

    $quizJs = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/quiz-questions.js');
    assertContains('QuizAPI.importQuestions(state.quizId, formData)', $quizJs);
    assertContains('QuizAPI.exportQuestions(state.quizId)', $quizJs);
    assertContains('QuizAPI.downloadImportTemplate(state.quizId)', $quizJs);
    assertContains('bindCsvEvents()', $quizJs);

    $bankPage = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/page/question-bank.html');
    assertContains('qbImportBtn', $bankPage);
    assertContains('qbExportBtn', $bankPage);
    assertContains('qbTemplateBtn', $bankPage);
    assertContains('qbImportModal', $bankPage);

    $bankJs = (string) file_get_contents($projectRoot . '/lms-frontend/teacher/js/question-bank.js');
    assertContains('QuestionBankAPI.importQuestions(formData)', $bankJs);
    assertContains('QuestionBankAPI.exportQuestions(state.filters)', $bankJs);
    assertContains('QuestionBankAPI.downloadImportTemplate()', $bankJs);
    assertContains('bindCsvEvents()', $bankJs);
});

test('Bank CSV append keeps private drafts, freezes versions and skips duplicates', function () {
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }
    foreach (['questions', 'question_bank_options', 'question_versions'] as $table) {
        if (!testTableExists($pdo, $table)) {
            skip('the central question bank has not been migrated');
        }
    }

    $db = Database::getInstance()->getConnection();
    $repo = new QuestionBankRepository();
    $actor = ['user_id' => 990111, 'role' => 'teacher', 'roles' => ['teacher'], 'institution_id' => 1];
    $marker = 'bank csv ' . uniqid();

    $db->beginTransaction();
    try {
        $firstText = $marker . ' one';
        $prepared = [
            ['row' => 2, 'question' => QuestionCsv::buildQuestion([
                'question_text' => $firstText,
                'question_type' => 'multiple_choice',
                'option_a' => 'Option A',
                'option_b' => 'Option B',
                'correct_answer' => 'A',
            ])],
            // Trailing whitespace is trimmed before duplicate detection.
            ['row' => 3, 'question' => QuestionCsv::buildQuestion([
                'question_text' => $firstText . '   ',
                'question_type' => 'multiple_choice',
                'option_a' => 'Option A',
                'option_b' => 'Option B',
                'correct_answer' => 'A',
            ])],
            ['row' => 4, 'question' => QuestionCsv::buildQuestion([
                'question_text' => $marker . ' two',
                'question_type' => 'true_false',
                'correct_answer' => 'TRUE',
            ])],
        ];

        $summary = $repo->importQuestions($prepared, $actor, 1);
        assertSame(2, $summary['imported'], 'Two new questions should be appended');
        assertSame(1, $summary['skipped'], 'Duplicate text should be skipped');
        assertSame(0, $summary['failed'], 'Valid rows should not fail');

        $rows = $db->prepare('SELECT question_id, visibility, approval_status, owner_user_id
            FROM questions WHERE question_text LIKE :marker ORDER BY question_id ASC');
        $rows->execute(['marker' => $marker . '%']);
        $created = $rows->fetchAll(PDO::FETCH_ASSOC);
        assertSame(2, count($created), 'Only non-duplicate rows are stored');

        $versionStmt = $db->prepare('SELECT COUNT(*) FROM question_versions WHERE question_id = :id');
        foreach ($created as $row) {
            assertSame('private', $row['visibility'], 'Imported bank questions stay private');
            assertSame('draft', $row['approval_status'], 'Imported bank questions start as drafts');
            assertSame(990111, (int) $row['owner_user_id'], 'Importer owns the new question');
            $versionStmt->execute(['id' => $row['question_id']]);
            assertSame(1, (int) $versionStmt->fetchColumn(), 'Each import creates its first version');
        }

        $optionStmt = $db->prepare('SELECT COUNT(*) FROM question_bank_options WHERE question_id = :id');
        $optionStmt->execute(['id' => $created[0]['question_id']]);
        assertSame(2, (int) $optionStmt->fetchColumn(), 'Multiple choice options are imported');

        $exportRows = $repo->questionsForExport(['q' => $marker], $actor);
        assertSame(2, count($exportRows), 'Export only returns visible questions');
        assertContains('option_a', QuestionCsv::serialize($exportRows), 'Export uses the canonical CSV header');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
