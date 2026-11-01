<?php
/**
 * Migration: Add profile_photo to users (legacy databases only).
 *
 * Fresh installs already have this column in db/init/01-lms.sql. Run this once
 * against older databases:
 *
 *   php lms-api/migrations/add_profile_photo_to_users.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'lms'
    );

    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => 'users', 'column' => 'profile_photo']);

    if ((int) $stmt->fetchColumn() > 0) {
        echo "Column users.profile_photo already exists. Nothing to do.\n";
        exit(0);
    }

    $pdo->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(500) DEFAULT NULL AFTER deleted_at');
    echo "Added column users.profile_photo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
