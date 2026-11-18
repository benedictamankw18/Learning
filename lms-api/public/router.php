<?php
/**
 * Router script for PHP built-in development server
 * This ensures all API requests go through index.php, even those with file extensions
 */

function sendCorsHeaders(): void
{
    $allowedOrigins = $_ENV['ALLOWED_ORIGINS'] ?? '*';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($allowedOrigins === '*') {
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
    } else {
        $originsArray = array_values(array_filter(array_map(function ($item) {
            return trim((string) $item, " \t\n\r\0\x0B\"'");
        }, explode(',', $allowedOrigins))));

        // Private / LAN origins are allowed so the dev server works from any
        // machine on the local network, regardless of the server's IP.
        $isPrivateOrigin = $origin !== '' && preg_match(
            '#^https?://(?:localhost|127\.0\.0\.1|\[::1\]|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(:\d+)?$#i',
            $origin
        );

        if ($origin && (in_array($origin, $originsArray, true) || $isPrivateOrigin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 3600');
}

/**
 * Resolve a path relative to the uploads directory.
 *
 * Returns null when the resolved file is missing or lands outside the
 * uploads directory, so requests such as "/uploads/../../.env" cannot read
 * arbitrary files on disk.
 */
function resolveUploadFile(string $relativePath): ?string
{
    $baseDir = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    if ($baseDir === false) {
        return null;
    }

    $relativePath = str_replace(["\0", '\\'], ['', '/'], $relativePath);
    if ($relativePath === '') {
        return null;
    }

    $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    $basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($candidate, $basePrefix)) {
        return null;
    }

    return $candidate;
}

/**
 * Stream an uploaded file with safe headers.
 *
 * Unknown extensions (including legacy SVG files) are sent as
 * application/octet-stream with nosniff so they download instead of being
 * rendered inline as active content.
 */
function serveUploadFile(string $filePath): void
{
    $mimeMap = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimeMap[$extension] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
}

// Get the requested URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If the URI starts with /api or matches an API pattern, route through index.php
if (preg_match('#^/api/#', $uri)) {
    sendCorsHeaders();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'message' => 'OK']);
        return true;
    }

    require __DIR__ . '/index.php';
    return true;
}

// For file uploads directory, serve files directly from lms-api/uploads/
// Must be checked BEFORE the generic static-file extension check below
if (preg_match('#^/uploads/#', $uri)) {
    $filePath = resolveUploadFile(ltrim(substr($uri, strlen('/uploads')), '/'));
    if ($filePath !== null) {
        serveUploadFile($filePath);
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'File not found']);
    return true;
}

// Backward compatibility: old records may reference /materials/<file>.
// Map them to /uploads/materials/<file> when running the PHP dev server.
if (preg_match('#^/materials/#', $uri)) {
    $filePath = resolveUploadFile('materials/' . ltrim(substr($uri, strlen('/materials')), '/'));
    if ($filePath !== null) {
        serveUploadFile($filePath);
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'File not found']);
    return true;
}

// For static files (css, js, images), serve them directly from public/
if (preg_match('/\.(?:css|js|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot)$/', $uri)) {
    return false; // Serve the file normally
}

// Everything else goes through index.php
require __DIR__ . '/index.php';
return true;