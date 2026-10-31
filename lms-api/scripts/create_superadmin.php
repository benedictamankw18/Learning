<?php
/**
 * Provision or rotate the platform super administrator.
 *
 * Usage:
 *   php lms-api/scripts/create_superadmin.php --email=admin@example.com
 *   php lms-api/scripts/create_superadmin.php --email=admin@example.com --username=superadmin --force
 *
 * The password is generated randomly unless --password is supplied. Passing a
 * password on the command line leaves it in shell history, so prefer the
 * generated password and store it in a password manager.
 *
 * This script only runs from the command line and reads lms-api/.env.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$options = [];
$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }

    $arg = substr($arg, 2);
    if (strpos($arg, '=') !== false) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    } else {
        $flags[$arg] = true;
    }
}

$username = trim((string) ($options['username'] ?? 'superadmin'));
$email = trim((string) ($options['email'] ?? ''));
$firstName = trim((string) ($options['first-name'] ?? 'Platform'));
$lastName = trim((string) ($options['last-name'] ?? 'Super Administrator'));
$force = isset($flags['force']);
$suppliedPassword = isset($options['password']) ? (string) $options['password'] : null;

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "ERROR: --email is required and must be a valid email address.\n");
    exit(1);
}

if (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1) {
    fwrite(STDERR, "ERROR: --username must be 3-50 characters (letters, digits, dot, underscore, hyphen).\n");
    exit(1);
}

if ($suppliedPassword !== null && strlen($suppliedPassword) < 12) {
    fwrite(STDERR, "ERROR: --password must be at least 12 characters.\n");
    exit(1);
}

$password = $suppliedPassword ?? bin2hex(random_bytes(12));

$required = ['DB_HOST', 'DB_NAME', 'DB_USER'];
$missing = array_filter($required, static function (string $key): bool {
    return !isset($_ENV[$key]) || $_ENV[$key] === '';
});

if ($missing !== []) {
    fwrite(STDERR, "ERROR: Missing database configuration: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Copy lms-api/.env.example to lms-api/.env and fill it in.\n");
    exit(1);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'],
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME']
    );

    $db = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Could not connect to the database. Check lms-api/.env.\n");
    exit(1);
}

try {
    $roleStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = 'super_admin' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    if ($roleId === false) {
        fwrite(STDERR, "ERROR: The super_admin role does not exist. Import db/init/01-lms.sql and 02-insert.sql first.\n");
        exit(1);
    }

    $roleId = (int) $roleId;

    $existingStmt = $db->prepare(
        'SELECT user_id, username, email FROM users WHERE username = :username OR email = :email LIMIT 1'
    );
    $existingStmt->execute(['username' => $username, 'email' => $email]);
    $existing = $existingStmt->fetch();

    if ($existing !== false && !$force) {
        fwrite(STDERR, "ERROR: A user with that username or email already exists. Re-run with --force to rotate the password.\n");
        exit(1);
    }

    // When rotating, report the account that actually matched rather than the
    // arguments supplied on the command line.
    if ($existing !== false) {
        $username = (string) $existing['username'];
        $email = (string) $existing['email'];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $db->beginTransaction();

    if ($existing !== false) {
        $userId = (int) $existing['user_id'];

        $updateStmt = $db->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 is_super_admin = 1,
                 is_active = 1,
                 deleted_at = NULL
             WHERE user_id = :user_id'
        );
        $updateStmt->execute([
            'password_hash' => $passwordHash,
            'user_id' => $userId,
        ]);

        $deleteRoles = $db->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $deleteRoles->execute(['user_id' => $userId]);

        $action = 'rotated';
    } else {
        $insertStmt = $db->prepare(
            'INSERT INTO users
                (uuid, institution_id, username, email, password_hash, first_name, last_name, is_super_admin, is_active)
             VALUES
                (UUID(), NULL, :username, :email, :password_hash, :first_name, :last_name, 1, 1)'
        );
        $insertStmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $userId = (int) $db->lastInsertId();
        $action = 'created';
    }

    $assignStmt = $db->prepare(
        'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
    );
    $assignStmt->execute(['user_id' => $userId, 'role_id' => $roleId]);

    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, "ERROR: Provisioning failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Super administrator {$action} successfully.\n";
echo "  Username: {$username}\n";
echo "  Email:    {$email}\n";

if ($suppliedPassword === null) {
    echo "  Password: {$password}\n";
    echo "\nStore this password now; it will not be shown again.\n";
} else {
    echo "  Password: (the value supplied with --password)\n";
}
