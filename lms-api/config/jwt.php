<?php

/**
 * JWT configuration.
 *
 * This file is intentionally strict: it validates JWT_SECRET every time it is
 * loaded, including from CLI scripts or alternate entry points. Returning an
 * array is not enough to bypass the check.
 */
$jwtSecret = $_ENV['JWT_SECRET'] ?? $_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';

$knownPlaceholders = [
    'your-secret-key-change-this',
    'change-this-to-a-long-random-secret-min-32-chars',
    'your-super-secret-key-change-this-in-production-min-32-chars',
    'secret',
    'changeme',
    'password',
];

$configurationError = null;

if (!is_string($jwtSecret) || trim($jwtSecret) === '') {
    $configurationError = 'JWT_SECRET is required.';
} elseif (strlen($jwtSecret) < 32) {
    $configurationError = 'JWT_SECRET must be at least 32 characters long.';
} elseif (in_array($jwtSecret, $knownPlaceholders, true)
    || str_contains($jwtSecret, 'change-this')
    || str_contains($jwtSecret, 'your-secret-key')) {
    $configurationError = 'JWT_SECRET must not use a known placeholder value.';
}

if ($configurationError !== null) {
    throw new \RuntimeException($configurationError);
}

return [
    'secret' => $jwtSecret,
    'issuer' => $_ENV['JWT_ISSUER'] ?? $_SERVER['JWT_ISSUER'] ?? getenv('JWT_ISSUER') ?: 'lms-api',
    'audience' => $_ENV['JWT_AUDIENCE'] ?? $_SERVER['JWT_AUDIENCE'] ?? getenv('JWT_AUDIENCE') ?: 'lms-client',
    'access_expiry' => (int) ($_ENV['JWT_ACCESS_EXPIRY'] ?? $_SERVER['JWT_ACCESS_EXPIRY'] ?? getenv('JWT_ACCESS_EXPIRY') ?: 3600),
    'refresh_expiry' => (int) ($_ENV['JWT_REFRESH_EXPIRY'] ?? $_SERVER['JWT_REFRESH_EXPIRY'] ?? getenv('JWT_REFRESH_EXPIRY') ?: 604800),
    'algorithm' => 'HS256',
    'app_env' => $appEnv,
];
