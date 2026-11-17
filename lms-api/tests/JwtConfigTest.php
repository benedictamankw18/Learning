<?php

declare(strict_types=1);

/**
 * JWT configuration is validated at load time and throws on unsafe values.
 * Each case runs in a child process because config/jwt.php is only evaluated
 * once per process (JWTHandler caches it).
 */

function buildJwtConfigSnippet(): string
{
    $configPath = var_export(dirname(__DIR__) . '/config/jwt.php', true);

    return 'try {'
        . '$config = require ' . $configPath . ';'
        . 'echo "OK:" . json_encode($config);'
        . '} catch (\\Throwable $e) {'
        . 'echo "ERR:" . get_class($e) . ":" . $e->getMessage();'
        . '}';
}

function buildJwtHandlerSnippet(string $body): string
{
    $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

    return 'require ' . $autoload . ';' . $body;
}

function runJwtConfig(array $env): array
{
    return runPhpSnippet(buildJwtConfigSnippet(), $env);
}

function decodeJwtConfigResult(array $result): array
{
    assertSame(0, $result['code'], 'JWT config child process should exit cleanly');
    assertContains('OK:', $result['stdout'], 'JWT config did not load: ' . $result['stdout'] . $result['stderr']);

    $json = substr($result['stdout'], strpos($result['stdout'], 'OK:') + 3);
    $config = json_decode($json, true);
    assertTrue(is_array($config), 'JWT config JSON was invalid: ' . $json);

    return $config;
}

test('JWT config fails closed when JWT_SECRET is missing', function () {
    $result = runJwtConfig([]);

    assertContains('ERR:RuntimeException:JWT_SECRET is required.', $result['stdout']);
    assertFalse(str_contains($result['stdout'], 'OK:'), 'Missing JWT_SECRET must not produce a config');
});

test('JWT config rejects empty and whitespace-only secrets', function () {
    foreach (['', '   ', "\t\n"] as $value) {
        $result = runJwtConfig(['JWT_SECRET' => $value]);
        assertContains('ERR:RuntimeException:JWT_SECRET is required.', $result['stdout'], 'Secret value: ' . var_export($value, true));
        assertFalse(str_contains($result['stdout'], 'OK:'));
    }
});

test('JWT config rejects secrets shorter than 32 characters', function () {
    foreach (['short', '1234567890123456789012345678901'] as $value) {
        $result = runJwtConfig(['JWT_SECRET' => $value]);
        assertContains('ERR:RuntimeException:JWT_SECRET must be at least 32 characters long.', $result['stdout']);
        assertFalse(str_contains($result['stdout'], 'OK:'));
    }
});

test('JWT config rejects known placeholder secrets', function () {
    $placeholders = [
        'change-this-to-a-long-random-secret-min-32-chars',
        'your-super-secret-key-change-this-in-production-min-32-chars',
        'your-secret-key-abcdefghijklmnopqrstuvwxyz',
        'secret',
        'changeme',
        'password',
    ];

    foreach ($placeholders as $value) {
        $result = runJwtConfig(['JWT_SECRET' => $value]);

        // Values shorter than 32 characters fail the length check first.
        $expected = strlen($value) < 32
            ? 'JWT_SECRET must be at least 32 characters long.'
            : 'JWT_SECRET must not use a known placeholder value.';

        assertContains('ERR:RuntimeException:' . $expected, $result['stdout'], 'Secret value: ' . $value);
        assertFalse(str_contains($result['stdout'], 'OK:'));
    }
});

test('JWT config loads a valid secret and applies safe defaults', function () {
    $secret = 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6';
    $config = decodeJwtConfigResult(runJwtConfig(['JWT_SECRET' => $secret]));

    assertSame($secret, $config['secret']);
    assertSame('lms-api', $config['issuer']);
    assertSame('lms-client', $config['audience']);
    assertSame('HS256', $config['algorithm']);
    assertSame(3600, $config['access_expiry']);
    assertSame(604800, $config['refresh_expiry']);
    assertSame('production', $config['app_env']);
});

test('JWT config honours explicit issuer, audience, expiry, and environment overrides', function () {
    $config = decodeJwtConfigResult(runJwtConfig([
        'JWT_SECRET' => str_repeat('k', 48),
        'JWT_ISSUER' => 'lms-test-issuer',
        'JWT_AUDIENCE' => 'lms-test-audience',
        'JWT_ACCESS_EXPIRY' => '120',
        'JWT_REFRESH_EXPIRY' => '7200',
        'APP_ENV' => 'testing',
    ]));

    assertSame('lms-test-issuer', $config['issuer']);
    assertSame('lms-test-audience', $config['audience']);
    assertSame(120, $config['access_expiry']);
    assertSame(7200, $config['refresh_expiry']);
    assertSame('testing', $config['app_env']);
});

test('JWTHandler generates and validates access and refresh tokens with a valid secret', function () {
    $snippet = buildJwtHandlerSnippet(
        '$access = \\App\\Utils\\JWTHandler::generateAccessToken(["user_id" => 42, "role" => "teacher"]);'
        . '$decodedAccess = \\App\\Utils\\JWTHandler::validateToken($access);'
        . '$jti = \\App\\Utils\\JWTHandler::generateJti();'
        . '$refresh = \\App\\Utils\\JWTHandler::generateRefreshToken(42, $jti);'
        . '$decodedRefresh = \\App\\Utils\\JWTHandler::validateRefreshToken($refresh);'
        . '$accessAsRefresh = \\App\\Utils\\JWTHandler::validateRefreshToken($access);'
        . 'echo json_encode(['
        . '"access_ok" => $decodedAccess !== null,'
        . '"access_user" => $decodedAccess->data->user_id ?? null,'
        . '"access_role" => $decodedAccess->data->role ?? null,'
        . '"refresh_ok" => $decodedRefresh !== null,'
        . '"refresh_type" => $decodedRefresh->type ?? null,'
        . '"refresh_jti" => $decodedRefresh->jti ?? null,'
        . '"refresh_user" => $decodedRefresh->user_id ?? null,'
        . '"jti_length" => strlen($jti),'
        . '"access_as_refresh" => $accessAsRefresh !== null,'
        . ']);'
    );

    $result = runPhpSnippet($snippet, ['JWT_SECRET' => str_repeat('z', 40)]);
    assertSame(0, $result['code'], 'Token child process failed: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assertTrue(is_array($payload), 'Token output was not JSON: ' . $result['stdout']);

    assertTrue($payload['access_ok']);
    assertSame(42, $payload['access_user']);
    assertSame('teacher', $payload['access_role']);

    assertTrue($payload['refresh_ok']);
    assertSame('refresh', $payload['refresh_type']);
    assertSame(42, $payload['refresh_user']);
    assertSame(64, $payload['jti_length']);
    assertFalse($payload['access_as_refresh'], 'An access token must not validate as a refresh token');
});

test('JWTHandler fails closed when the JWT secret is unsafe', function () {
    $snippet = buildJwtHandlerSnippet(
        'try {'
        . '\\App\\Utils\\JWTHandler::generateAccessToken(["user_id" => 1]);'
        . 'echo "LOADED";'
        . '} catch (\\Throwable $e) {'
        . 'echo "ERR:" . get_class($e) . ":" . $e->getMessage();'
        . '}'
    );

    $result = runPhpSnippet($snippet, []);
    assertContains('ERR:RuntimeException:JWT_SECRET is required.', $result['stdout']);
    assertFalse(str_contains($result['stdout'], 'LOADED'));
});
