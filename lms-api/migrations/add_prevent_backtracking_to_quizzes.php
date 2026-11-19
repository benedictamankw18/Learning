<?php
/**
 * Migration: Add prevent_backtracking column to quizzes table
 * Run this with: php migrations/add_prevent_backtracking_to_quizzes.php
 *
 * Controls the "Prevent Going Back" quiz setting. When enabled, a student
 * attempt may only move forward: previous questions cannot be reopened.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') && !strpos($line, '#')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

try {
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $dbname = $_ENV['DB_NAME'] ?? 'lms';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    $port = $_ENV['DB_PORT'] ?? 3306;

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "🔄 Running migration: Add prevent_backtracking to quizzes table...\n";

    $stmt = $pdo->prepare("SHOW COLUMNS FROM quizzes LIKE 'prevent_backtracking'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo "✅ Column prevent_backtracking already exists on quizzes. Skipping migration.\n";
        exit(0);
    }

    echo "  → Adding column prevent_backtracking to quizzes...\n";
    $pdo->exec(
        "ALTER TABLE quizzes ADD COLUMN prevent_backtracking TINYINT(1) NOT NULL DEFAULT 0 AFTER randomize_questions"
    );

    echo "✅ Migration completed successfully!\n";
    exit(0);

} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
