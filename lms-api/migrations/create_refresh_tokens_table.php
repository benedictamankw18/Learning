<?php
/**
 * Migration: Create the refresh_tokens table.
 *
 * Fresh Docker installs also get this table from db/init/03-refresh-tokens.sql.
 * Run this once against an existing database:
 *
 *   php lms-api/migrations/create_refresh_tokens_table.php
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `refresh_tokens` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `jti` char(64) NOT NULL,
            `token_hash` char(64) NOT NULL,
            `expires_at` datetime NOT NULL,
            `revoked_at` datetime DEFAULT NULL,
            `replaced_by_jti` char(64) DEFAULT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_refresh_jti` (`jti`),
            UNIQUE KEY `unique_refresh_token_hash` (`token_hash`),
            KEY `idx_refresh_user` (`user_id`),
            KEY `idx_refresh_expires` (`expires_at`),
            CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`)
                REFERENCES `users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    echo "refresh_tokens table is ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
