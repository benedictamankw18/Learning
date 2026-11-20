<?php

/**
 * Minimal standalone test harness.
 *
 * No PHPUnit dependency. Run with:
 *
 *   php lms-api/tests/run.php
 *   php lms-api/tests/run.php --db-host=localhost --db-user=root --db-pass=
 *
 * Database-backed tests skip automatically when the database is unavailable or
 * the expected table has not been migrated yet.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$testRoot = dirname(__DIR__);

if (is_file($testRoot . '/.env')) {
    Dotenv::createImmutable($testRoot)->safeLoad();
}

// PHP's getopt() drops options supplied with an empty value (for example
// --db-pass=). Re-read the raw arguments so an empty password can override a
// non-empty value loaded from .env.
$dbOptions = getopt('', ['db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::']);
if (!is_array($dbOptions)) {
    $dbOptions = [];
}

foreach ($_SERVER['argv'] ?? [] as $rawArg) {
    if (preg_match('/^--(db-[a-z-]+)=$/', (string) $rawArg, $matches)) {
        $dbOptions[$matches[1]] = '';
    }
}

$dbOptionMap = [
    'db-host' => 'DB_HOST',
    'db-port' => 'DB_PORT',
    'db-name' => 'DB_NAME',
    'db-user' => 'DB_USER',
    'db-pass' => 'DB_PASS',
];

foreach ($dbOptionMap as $option => $envKey) {
    if (array_key_exists($option, $dbOptions) && is_string($dbOptions[$option])) {
        $_ENV[$envKey] = $dbOptions[$option];
    }
}

// TEST_DB_* environment variables are an alternative to CLI flags.
foreach ($dbOptionMap as $envKey) {
    $testKey = 'TEST_' . $envKey;
    $value = getenv($testKey);
    if ($value !== false) {
        $_ENV[$envKey] = $value;
    }
}

final class TestFailure extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
{
}

/** @var array<int, array{0:string,1:callable}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__assertions'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function testAssertions(): int
{
    return (int) $GLOBALS['__assertions'];
}

function fail(string $message): void
{
    throw new TestFailure($message);
}

function skip(string $reason): void
{
    throw new TestSkipped($reason);
}

function assertTrue($value, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if ($value !== true) {
        fail($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
    }
}

function assertFalse($value, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if ($value !== false) {
        fail($message !== '' ? $message : 'Expected false, got ' . var_export($value, true));
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if ($expected !== $actual) {
        $detail = $message !== '' ? $message . ': ' : '';
        fail($detail . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertNotSame($expected, $actual, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if ($expected === $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'Did not expect ' . var_export($actual, true));
    }
}

function assertNotNull($value, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if ($value === null) {
        fail($message !== '' ? $message : 'Expected a non-null value');
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    $GLOBALS['__assertions']++;
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . "Expected to find {$needle}");
    }
}

/**
 * @param class-string<Throwable> $expectedClass
 */
function assertThrows(callable $fn, string $expectedClass, string $messageContains = ''): void
{
    $GLOBALS['__assertions']++;
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            fail('Expected ' . $expectedClass . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($messageContains !== '' && !str_contains($e->getMessage(), $messageContains)) {
            fail('Exception message did not contain ' . var_export($messageContains, true) . ': ' . $e->getMessage());
        }
        return;
    }

    fail('Expected ' . $expectedClass . ' to be thrown');
}

/**
 * Shared PDO connection used by database-backed tests.
 */
function testDatabase(): ?PDO
{
    static $pdo = null;
    static $attempted = false;

    if ($attempted) {
        return $pdo;
    }
    $attempted = true;

    $host = (string) ($_ENV['DB_HOST'] ?? 'localhost');
    $port = (string) ($_ENV['DB_PORT'] ?? '3306');
    $name = (string) ($_ENV['DB_NAME'] ?? 'lms');
    $user = (string) ($_ENV['DB_USER'] ?? 'root');
    $pass = (string) ($_ENV['DB_PASS'] ?? '');

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}

function testTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Extract a named class method body from a PHP source string.
 *
 * Used by static policy tests that need to inspect one method without matching
 * unrelated role gates elsewhere in the same controller.
 */
function testMethodBody(string $source, string $method): string
{
    $pattern = '/\b(?:public|private|protected)\s+function\s+'
        . preg_quote($method, '/')
        . '\s*\([^)]*\)\s*(?::\s*[^\{\s]+)?\s*\{/';

    if (!preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = $matches[0][1] + strlen($matches[0][0]);
    $length = strlen($source);
    $depth = 1;

    for ($i = $start; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start);
            }
        }
    }

    return '';
}

/**
 * Run a PHP snippet in a clean child process. Used to test configuration files
 * that throw or cache static state.
 *
 * @param array<string,string> $env
 * @return array{code:int,stdout:string,stderr:string}
 */
function runPhpSnippet(string $snippet, array $env = []): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Preserve only the variables php.exe needs to start on Windows.
    $baseEnv = [];
    foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $baseEnv[$key] = $value;
        }
    }

    $process = proc_open(
        [PHP_BINARY, '-r', $snippet],
        $descriptors,
        $pipes,
        null,
        array_merge($baseEnv, $env)
    );

    if (!is_resource($process)) {
        fail('Could not start a PHP child process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => (int) $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function runTests(): int
{
    $tests = $GLOBALS['__tests'];
    $failures = [];
    $skipped = [];
    $passed = 0;

    echo "LMS API test suite\n";
    echo str_repeat('-', 72) . "\n";

    foreach ($tests as [$name, $fn]) {
        try {
            $fn();
            $passed++;
            echo "  PASS  {$name}\n";
        } catch (TestSkipped $e) {
            $skipped[] = [$name, $e->getMessage()];
            echo "  SKIP  {$name} ({$e->getMessage()})\n";
        } catch (Throwable $e) {
            $failures[] = [$name, $e];
            echo "  FAIL  {$name}\n";
        }
    }

    echo str_repeat('-', 72) . "\n";
    printf(
        "Tests: %d | passed: %d | failed: %d | skipped: %d | assertions: %d\n",
        count($tests),
        $passed,
        count($failures),
        count($skipped),
        testAssertions()
    );

    foreach ($failures as [$name, $e]) {
        echo "\nFAILURE: {$name}\n";
        echo '  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        $trace = explode("\n", $e->getTraceAsString());
        foreach (array_slice($trace, 0, 5) as $line) {
            echo '  ' . $line . "\n";
        }
    }

    return $failures === [] ? 0 : 1;
}
