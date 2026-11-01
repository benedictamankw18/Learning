<?php

/**
 * CORS policy.
 *
 * Production must use an explicit ALLOWED_ORIGINS allowlist. A wildcard is
 * only honoured in development, and credentials are never enabled because the
 * frontend authenticates with Bearer tokens rather than cookies.
 */

$appEnv = $_ENV['APP_ENV'] ?? 'production';
$isDevelopment = $appEnv === 'development';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOriginsRaw = trim((string) ($_ENV['ALLOWED_ORIGINS'] ?? ''));

// Backwards-compatible fallback for deployments that only set FRONTEND_URL.
if ($allowedOriginsRaw === '' && !empty($_ENV['FRONTEND_URL'])) {
    $allowedOriginsRaw = (string) $_ENV['FRONTEND_URL'];
}

$allowedOrigins = array_values(array_filter(array_map(static function ($item): string {
    return rtrim(trim((string) $item, " \t\n\r\0\x0B\"'"), '/');
}, explode(',', $allowedOriginsRaw)), static function (string $value): bool {
    return $value !== '';
}));

$isWildcard = in_array('*', $allowedOrigins, true);
$isAllowed = false;

if ($origin !== '') {
    if (in_array($origin, $allowedOrigins, true)) {
        $isAllowed = true;
    } elseif ($isDevelopment && $isWildcard) {
        // Reflecting the origin is only acceptable for local development.
        $isAllowed = true;
    } elseif ($isDevelopment && preg_match(
        '#^https?://(?:localhost|127\.0\.0\.1|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(:\d+)?$#i',
        $origin
    )) {
        // Allow local frontend dev servers on any port.
        $isAllowed = true;
    }
}

if (!$isDevelopment && ($allowedOrigins === [] || $isWildcard) && $origin !== '') {
    error_log('CORS misconfiguration: ALLOWED_ORIGINS must be an explicit allowlist in production.');
}

if ($isAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 3600');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    // A preflight from an unapproved origin must not receive permission headers.
    http_response_code($origin === '' || $isAllowed ? 204 : 403);
    exit();
}
