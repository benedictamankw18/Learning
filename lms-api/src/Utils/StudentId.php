<?php

namespace App\Utils;

/**
 * Student index-number helpers.
 *
 * Format: {PREFIX}-{YEAR}-{SEQUENCE}, for example PSHST-2026-0001.
 *
 * The prefix comes from the institution name so every school issues its own
 * recognisable IDs instead of a generic "STU" prefix:
 * "PRESBYTERIAN SENIOR HIGH SCHOOL, TEMA" -> "PSHST".
 *
 * One implementation backs the "Add student" modal, the student CSV import and
 * the users CSV import so the three paths cannot drift apart.
 */
final class StudentId
{
    public const DEFAULT_PREFIX = 'SHS';
    public const MAX_PREFIX_LENGTH = 6;

    /**
     * Build a prefix from an institution name, falling back to its code and
     * then to "SHS" when neither yields anything usable.
     */
    public static function prefixFromInstitution(?string $name, ?string $code = null): string
    {
        $initials = self::initialsFromName((string) $name);
        if ($initials !== '') {
            return $initials;
        }

        $fromName = self::sanitize((string) $name);
        if ($fromName !== '') {
            return $fromName;
        }

        $fromCode = self::sanitize((string) $code);
        if ($fromCode !== '') {
            return $fromCode;
        }

        return self::DEFAULT_PREFIX;
    }

    /**
     * Word initials of an institution name, uppercase, capped at six
     * characters. Returns an empty string when there are fewer than two
     * words with letters.
     */
    public static function initialsFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        preg_match_all('/\b[A-Za-z]/', $name, $matches);
        $initials = $matches[0] ?? [];

        if (count($initials) < 2) {
            return '';
        }

        return strtoupper(implode('', array_slice($initials, 0, self::MAX_PREFIX_LENGTH)));
    }

    /**
     * Format a complete student ID. The sequence is zero-padded to four
     * digits; longer sequences are kept as-is.
     */
    public static function format(string $prefix, int $year, int $sequence): string
    {
        $prefix = self::normalizePrefix($prefix);
        $sequence = max(1, $sequence);

        return sprintf('%s-%d-%04d', $prefix, $year, $sequence);
    }

    /**
     * Extract the numeric sequence from an ID that matches the given prefix
     * and year. Returns 0 when the ID does not match.
     */
    public static function parseSequence(string $studentIdNumber, string $prefix, int $year): int
    {
        $prefix = self::normalizePrefix($prefix);
        $pattern = '/^' . preg_quote($prefix, '/') . '-' . preg_quote((string) $year, '/') . '-(\d+)$/i';

        if (preg_match($pattern, trim($studentIdNumber), $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * Normalise a prefix to uppercase letters/digits, capped at six
     * characters. Empty values become the default prefix.
     */
    public static function normalizePrefix(string $prefix): string
    {
        $prefix = self::sanitize($prefix);

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    private static function sanitize(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? '';
        $value = strtoupper($value);

        if ($value === '') {
            return '';
        }

        return substr($value, 0, self::MAX_PREFIX_LENGTH);
    }
}
