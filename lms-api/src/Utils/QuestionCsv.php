<?php

namespace App\Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * CSV import/export for quiz questions.
 *
 * One shared serializer backs both the export endpoint and the downloadable
 * template so the two formats cannot drift apart. Parsing uses fgetcsv so
 * quoted commas, doubled quotes and embedded newlines behave correctly.
 */
final class QuestionCsv
{
    public const MAX_ROWS = 500;
    public const MAX_FILE_BYTES = 2097152; // 2 MB

    public const QUESTION_TYPES = ['multiple_choice', 'true_false', 'short_answer', 'essay'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public const COLUMNS = [
        'question_text',
        'question_type',
        'difficulty',
        'points',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'option_e',
        'option_f',
        'correct_answer',
        'explanation',
        'image_url',
    ];

    private const OPTION_LABELS = ['A', 'B', 'C', 'D', 'E', 'F'];

    /**
     * Column names accepted in addition to the canonical ones.
     */
    private const HEADER_ALIASES = [
        'type' => 'question_type',
        'question' => 'question_text',
        'image' => 'image_url',
        'image_question' => 'image_url',
    ];

    /**
     * @return array<int,string>
     */
    public static function header(): array
    {
        return self::COLUMNS;
    }

    /**
     * Serialize questions (as returned by QuizRepository::getQuestions) to CSV.
     */
    public static function serialize(array $questions): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary CSV stream');
        }

        fputcsv($handle, self::COLUMNS, ',', '"', '');

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $options = self::optionsByLabel($question['options'] ?? []);
            $type = (string) ($question['question_type'] ?? '');
            $correctAnswer = trim((string) ($question['correct_answer'] ?? ''));

            if ($type === 'multiple_choice' && $correctAnswer === '') {
                $correctAnswer = self::firstCorrectLabel($question['options'] ?? []);
            }

            $row = [
                (string) ($question['question_text'] ?? ''),
                $type,
                (string) ($question['difficulty'] ?? 'medium'),
                (string) ((int) ($question['points'] ?? 1)),
            ];

            foreach (self::OPTION_LABELS as $label) {
                $row[] = $options[$label] ?? '';
            }

            $row[] = $correctAnswer;
            $row[] = (string) ($question['explanation'] ?? '');
            $row[] = (string) ($question['image_question'] ?? '');

            fputcsv($handle, array_map([self::class, 'neutralizeFormula'], $row), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * A starter file: guidance comments, the header, and three sample rows.
     */
    public static function template(): string
    {
        $hints = implode("\n", [
            '# Question import template',
            '# Columns: question_text, question_type, difficulty, points, option_a..option_f,',
            '#          correct_answer, explanation, image_url',
            '# question_type: multiple_choice | true_false | short_answer | essay',
            '# difficulty: easy | medium | hard (blank defaults to medium)',
            '# points: whole number, 1 or more (blank defaults to 1)',
            '# multiple_choice: fill option_a..option_d (option_e/option_f optional) and put',
            '#                  the correct option label (A-F) in correct_answer',
            '# true_false: put TRUE or FALSE in correct_answer; options are not needed',
            '# image_url: an uploaded image path, for example /api/upload/quiz-questions/name.png',
            '# Lines starting with # before the header row are ignored.',
            '# Delete these sample rows before importing your own questions.',
            '',
        ]) . "\n";

        return $hints . self::serialize(self::sampleQuestions());
    }

    /**
     * Parse CSV text into associative rows.
     *
     * Lines before the header may be blank or start with "#". After the header
     * every non-empty row is treated as data, so a question may start with "#".
     *
     * @return array{rows: array<int,array{row:int,data:array<string,string>}>, error: ?string}
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['rows' => [], 'error' => 'Could not read the CSV data.'];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $header = null;
        $rows = [];
        $record = 0;
        $error = null;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $record++;

            if (!is_array($cells)) {
                continue;
            }

            $cells = array_map(static fn ($cell) => $cell === null ? '' : (string) $cell, $cells);

            if ($header === null) {
                $first = trim($cells[0] ?? '');

                // Blank lines and comments are only allowed before the header.
                if ($first === '' || str_starts_with($first, '#')) {
                    continue;
                }

                $header = self::normalizeHeader($cells);
                $missing = [];
                foreach (['question_text', 'question_type'] as $required) {
                    if (!array_key_exists($required, $header)) {
                        $missing[] = $required;
                    }
                }

                if ($missing !== []) {
                    $error = 'The CSV is missing the required column(s): ' . implode(', ', $missing) . '.';
                    break;
                }

                continue;
            }

            if (self::isEmptyRow($cells)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $error = 'The CSV has more than ' . self::MAX_ROWS . ' question rows. Split it into smaller files.';
                break;
            }

            $data = [];
            foreach ($header as $name => $index) {
                $data[$name] = $cells[$index] ?? '';
            }

            $rows[] = ['row' => $record, 'data' => $data];
        }

        fclose($handle);

        if ($header === null && $error === null) {
            $error = 'The CSV file is empty or has no header row.';
        }

        return [
            'rows' => $error === null ? $rows : [],
            'error' => $error,
        ];
    }

    /**
     * Validate one parsed row and turn it into a QuizRepository::addQuestion payload.
     *
     * @throws InvalidArgumentException
     */
    public static function buildQuestion(array $data): array
    {
        $text = self::restoreFormula(trim((string) ($data['question_text'] ?? '')));
        if ($text === '') {
            throw new InvalidArgumentException('question_text is required.');
        }
        if (mb_strlen($text) > 65535) {
            throw new InvalidArgumentException('question_text is too long (maximum 65,535 characters).');
        }

        $type = strtolower(trim((string) ($data['question_type'] ?? '')));
        if ($type === '') {
            throw new InvalidArgumentException('question_type is required.');
        }
        if (!in_array($type, self::QUESTION_TYPES, true)) {
            throw new InvalidArgumentException(
                'question_type must be one of: ' . implode(', ', self::QUESTION_TYPES) . '.'
            );
        }

        $difficulty = strtolower(trim((string) ($data['difficulty'] ?? '')));
        if ($difficulty === '') {
            $difficulty = 'medium';
        }
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new InvalidArgumentException('difficulty must be easy, medium or hard.');
        }

        $pointsRaw = trim((string) ($data['points'] ?? ''));
        if ($pointsRaw === '') {
            $points = 1;
        } elseif (!preg_match('/^\d+$/', $pointsRaw) || (int) $pointsRaw < 1) {
            throw new InvalidArgumentException('points must be a whole number of 1 or more.');
        } else {
            $points = (int) $pointsRaw;
        }

        $explanation = self::restoreFormula(trim((string) ($data['explanation'] ?? '')));
        if ($explanation === '') {
            $explanation = null;
        }
        if ($explanation !== null && mb_strlen($explanation) > 65535) {
            throw new InvalidArgumentException('explanation is too long (maximum 65,535 characters).');
        }

        $image = trim((string) ($data['image_url'] ?? ''));
        if ($image === '') {
            $image = null;
        }
        if ($image !== null && mb_strlen($image) > 255) {
            throw new InvalidArgumentException('image_url is too long (maximum 255 characters).');
        }

        $correctRaw = self::restoreFormula(trim((string) ($data['correct_answer'] ?? '')));

        $options = [];
        $correctAnswer = null;

        if ($type === 'multiple_choice') {
            foreach (self::OPTION_LABELS as $label) {
                $optionText = self::restoreFormula(trim((string) ($data['option_' . strtolower($label)] ?? '')));
                if ($optionText === '') {
                    continue;
                }

                $options[] = [
                    'label' => $label,
                    'text' => $optionText,
                    'is_correct' => 0,
                ];
            }

            if (count($options) < 2) {
                throw new InvalidArgumentException(
                    'multiple_choice questions need at least two options (option_a and option_b).'
                );
            }

            $correctLabel = strtoupper($correctRaw);
            $match = null;
            foreach ($options as $index => $option) {
                if ($option['label'] === $correctLabel) {
                    $match = $index;
                    break;
                }
            }

            if ($match === null) {
                $labels = array_map(static fn ($option) => $option['label'], $options);
                throw new InvalidArgumentException(
                    'correct_answer must be one of the option labels present in the row ('
                    . implode(', ', $labels) . ').'
                );
            }

            $options[$match]['is_correct'] = 1;
            $correctAnswer = $correctLabel;
        } elseif ($type === 'true_false') {
            $correctAnswer = self::canonicalTrueFalse($correctRaw);
            if ($correctAnswer === null) {
                throw new InvalidArgumentException('true_false questions need TRUE or FALSE in correct_answer.');
            }
        } else {
            if (mb_strlen($correctRaw) > 500) {
                throw new InvalidArgumentException('correct_answer is too long (maximum 500 characters).');
            }
            $correctAnswer = $correctRaw === '' ? null : $correctRaw;
        }

        return [
            'question_text' => $text,
            'question_type' => $type,
            'points' => $points,
            'difficulty' => $difficulty,
            'correct_answer' => $correctAnswer,
            'explanation' => $explanation,
            'image_question' => $image,
            'options' => $options,
        ];
    }

    /**
     * @param array<int,string> $cells
     * @return array<string,int>
     */
    private static function normalizeHeader(array $cells): array
    {
        $map = [];

        foreach ($cells as $index => $name) {
            $key = strtolower(trim((string) $name));
            $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;

            if ($key === '') {
                continue;
            }

            $key = self::HEADER_ALIASES[$key] ?? $key;

            if (!array_key_exists($key, $map)) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @param array<int,string> $cells
     */
    private static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,string>
     */
    private static function optionsByLabel($options): array
    {
        $byLabel = [];

        if (!is_array($options)) {
            return $byLabel;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = strtoupper(trim((string) ($option['option_label'] ?? $option['label'] ?? '')));
            $text = trim((string) ($option['option_text'] ?? $option['text'] ?? ''));

            if ($label === '' || $text === '' || !in_array($label, self::OPTION_LABELS, true)) {
                continue;
            }

            $byLabel[$label] = $text;
        }

        return $byLabel;
    }

    private static function firstCorrectLabel($options): string
    {
        if (!is_array($options)) {
            return '';
        }

        foreach ($options as $option) {
            if (!is_array($option) || empty($option['is_correct'])) {
                continue;
            }

            $label = strtoupper(trim((string) ($option['option_label'] ?? $option['label'] ?? '')));
            if (in_array($label, self::OPTION_LABELS, true)) {
                return $label;
            }
        }

        return '';
    }

    private static function canonicalTrueFalse(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['true', 't', 'yes', 'y', '1', 'a'], true)) {
            return 'TRUE';
        }

        if (in_array($normalized, ['false', 'f', 'no', 'n', '0', 'b'], true)) {
            return 'FALSE';
        }

        return null;
    }

    /**
     * Prefix cells that a spreadsheet would treat as a formula.
     */
    private static function neutralizeFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Undo neutralizeFormula() so export -> import keeps the original text.
     */
    private static function restoreFormula(string $value): string
    {
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return substr($value, 1);
        }

        return $value;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function sampleQuestions(): array
    {
        return [
            [
                'question_text' => 'SAMPLE - Which of these is presentation software?',
                'question_type' => 'multiple_choice',
                'difficulty' => 'easy',
                'points' => 1,
                'correct_answer' => 'B',
                'explanation' => 'Microsoft PowerPoint is presentation software.',
                'image_question' => '',
                'options' => [
                    ['option_label' => 'A', 'option_text' => 'Microsoft Word', 'is_correct' => 0],
                    ['option_label' => 'B', 'option_text' => 'Microsoft PowerPoint', 'is_correct' => 1],
                    ['option_label' => 'C', 'option_text' => 'Microsoft Excel', 'is_correct' => 0],
                    ['option_label' => 'D', 'option_text' => 'Microsoft Access', 'is_correct' => 0],
                ],
            ],
            [
                'question_text' => 'SAMPLE - A spreadsheet stores data in rows and columns.',
                'question_type' => 'true_false',
                'difficulty' => 'easy',
                'points' => 1,
                'correct_answer' => 'TRUE',
                'explanation' => '',
                'image_question' => '',
                'options' => [],
            ],
            [
                'question_text' => 'SAMPLE - Name one input device.',
                'question_type' => 'short_answer',
                'difficulty' => 'medium',
                'points' => 2,
                'correct_answer' => 'Keyboard',
                'explanation' => 'A keyboard is an input device.',
                'image_question' => '',
                'options' => [],
            ],
        ];
    }
}
