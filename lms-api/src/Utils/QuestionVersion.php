<?php

namespace App\Utils;

/**
 * Shape shared by attempt snapshots and immutable question versions.
 *
 * Both store the same content payload, so normalizing it in one place keeps
 * attempt serving, review, and grading consistent across Phase 1B snapshots
 * and Phase 1C versions.
 */
final class QuestionVersion
{
    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public static function normalize(array $content): array
    {
        $options = [];
        foreach (($content['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'option_label' => (string) ($option['option_label'] ?? ($option['label'] ?? '')),
                'option_text' => (string) ($option['option_text'] ?? ($option['text'] ?? '')),
                'is_correct' => (int) ($option['is_correct'] ?? 0),
            ];
        }

        return [
            'question_id' => (int) ($content['question_id'] ?? 0),
            'question_text' => (string) ($content['question_text'] ?? ''),
            'image_question' => $content['image_question'] ?? null,
            'question_type' => (string) ($content['question_type'] ?? 'multiple_choice'),
            'points' => (int) ($content['points'] ?? 1),
            'difficulty' => $content['difficulty'] ?? null,
            'explanation' => $content['explanation'] ?? null,
            'correct_answer' => $content['correct_answer'] ?? null,
            'options' => $options,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function decode(?string $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return self::normalize($decoded);
    }

    /**
     * Serialize content for a version row or an attempt snapshot.
     *
     * @param array<string,mixed> $content
     */
    public static function encode(array $content): string
    {
        return (string) json_encode(
            self::normalize($content),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
