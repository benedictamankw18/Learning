<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\RefreshTokenRepository;
use App\Utils\JWTHandler;

/**
 * @return array{0:PDO,1:int}
 */
function refreshTokenFixture(): array
{
    $pdo = testDatabase();
    if (!$pdo) {
        skip('database is not reachable');
    }

    if (!testTableExists($pdo, 'refresh_tokens')) {
        skip('refresh_tokens table has not been migrated');
    }

    $userId = (int) $pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
    if ($userId <= 0) {
        skip('no users exist to own a refresh token');
    }

    return [$pdo, $userId];
}

/**
 * @param array<int,string> $jtis
 */
function deleteRefreshTokensByJti(PDO $pdo, array $jtis): void
{
    $jtis = array_values(array_filter($jtis, static fn ($jti) => is_string($jti) && $jti !== ''));
    if ($jtis === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($jtis), '?'));
    $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE jti IN ({$placeholders})");
    $stmt->execute($jtis);
}

test('RefreshTokenRepository stores only a SHA-256 token hash', function () {
    [$pdo, $userId] = refreshTokenFixture();

    $jti = bin2hex(random_bytes(32));
    $token = 'refresh-' . bin2hex(random_bytes(32));
    $repo = new RefreshTokenRepository();

    try {
        assertTrue($repo->create($userId, $token, $jti, time() + 3600, 'test-agent', '127.0.0.1'));

        $row = $repo->findByJti($jti);
        assertNotNull($row, 'Created refresh token was not found');
        assertSame($userId, (int) $row['user_id']);
        assertSame($jti, $row['jti']);
        assertSame(hash('sha256', $token), $row['token_hash']);
        assertNotSame($token, $row['token_hash'], 'The raw token must never be stored');
        assertSame(64, strlen((string) $row['token_hash']));
        assertTrue($row['revoked_at'] === null, 'A new token must not be revoked');
        assertSame('test-agent', $row['user_agent']);
        assertSame('127.0.0.1', $row['ip_address']);
    } finally {
        deleteRefreshTokensByJti($pdo, [$jti]);
    }
});

test('RefreshTokenRepository rotates a token and rejects reuse of the old token', function () {
    [$pdo, $userId] = refreshTokenFixture();

    $oldJti = bin2hex(random_bytes(32));
    $newJti = bin2hex(random_bytes(32));
    $reusedJti = bin2hex(random_bytes(32));
    $oldToken = 'old-' . bin2hex(random_bytes(32));
    $newToken = 'new-' . bin2hex(random_bytes(32));
    $reusedToken = 'reused-' . bin2hex(random_bytes(32));
    $repo = new RefreshTokenRepository();

    try {
        assertTrue($repo->create($userId, $oldToken, $oldJti, time() + 3600));
        $oldRow = $repo->findByJti($oldJti);
        assertNotNull($oldRow);

        assertTrue($repo->rotate(
            (int) $oldRow['id'],
            $newToken,
            $newJti,
            time() + 7200,
            'rotated-agent',
            '127.0.0.2'
        ));

        $revokedOld = $repo->findByJti($oldJti);
        assertNotNull($revokedOld);
        assertTrue($revokedOld['revoked_at'] !== null, 'Old token must be revoked after rotation');
        assertSame($newJti, $revokedOld['replaced_by_jti']);

        $replacement = $repo->findByJti($newJti);
        assertNotNull($replacement, 'Replacement token was not created');
        assertSame($userId, (int) $replacement['user_id']);
        assertSame(hash('sha256', $newToken), $replacement['token_hash']);
        assertTrue($replacement['revoked_at'] === null);
        assertSame('rotated-agent', $replacement['user_agent']);
        assertSame('127.0.0.2', $replacement['ip_address']);

        // Reusing the already-rotated token must fail and must not create a
        // third token.
        assertFalse($repo->rotate(
            (int) $oldRow['id'],
            $reusedToken,
            $reusedJti,
            time() + 7200
        ));
        assertSame(null, $repo->findByJti($reusedJti));
    } finally {
        deleteRefreshTokensByJti($pdo, [$oldJti, $newJti, $reusedJti]);
    }
});

test('RefreshTokenRepository revokes a single token and all tokens for a user', function () {
    [$pdo, $userId] = refreshTokenFixture();

    $jtiOne = bin2hex(random_bytes(32));
    $jtiTwo = bin2hex(random_bytes(32));
    $tokenOne = 'one-' . bin2hex(random_bytes(32));
    $tokenTwo = 'two-' . bin2hex(random_bytes(32));
    $repo = new RefreshTokenRepository();

    try {
        assertTrue($repo->create($userId, $tokenOne, $jtiOne, time() + 3600));
        assertTrue($repo->create($userId, $tokenTwo, $jtiTwo, time() + 3600));

        assertTrue($repo->revokeByToken($tokenOne));

        $rowOne = $repo->findByJti($jtiOne);
        $rowTwo = $repo->findByJti($jtiTwo);
        assertNotNull($rowOne);
        assertNotNull($rowTwo);
        assertTrue($rowOne['revoked_at'] !== null, 'revokeByToken must revoke the matching token');
        assertTrue($rowTwo['revoked_at'] === null, 'revokeByToken must not revoke other tokens');

        assertTrue($repo->revokeAllForUser($userId));

        $rowTwoAfter = $repo->findByJti($jtiTwo);
        assertNotNull($rowTwoAfter);
        assertTrue($rowTwoAfter['revoked_at'] !== null, 'revokeAllForUser must revoke remaining tokens');
    } finally {
        deleteRefreshTokensByJti($pdo, [$jtiOne, $jtiTwo]);
    }
});

test('RefreshTokenRepository deletes only tokens expired beyond the retention window', function () {
    [$pdo, $userId] = refreshTokenFixture();

    $expiredJti = bin2hex(random_bytes(32));
    $freshJti = bin2hex(random_bytes(32));
    $repo = new RefreshTokenRepository();

    // deleteExpired() affects every row in the table, so run this test inside a
    // transaction and roll back instead of mutating real token history.
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    try {
        assertTrue($repo->create($userId, 'expired-' . bin2hex(random_bytes(16)), $expiredJti, time() - (40 * 86400)));
        assertTrue($repo->create($userId, 'fresh-' . bin2hex(random_bytes(16)), $freshJti, time() + 3600));

        $deleted = $repo->deleteExpired(30);
        assertTrue($deleted >= 1, 'deleteExpired should remove the 40-day-old token');
        assertSame(null, $repo->findByJti($expiredJti));
        assertNotNull($repo->findByJti($freshJti), 'deleteExpired must keep unexpired tokens');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

test('JWTHandler hashes tokens deterministically without loading JWT configuration', function () {
    $token = 'refresh-' . bin2hex(random_bytes(16));

    assertSame(hash('sha256', $token), JWTHandler::hashToken($token));
    assertSame(JWTHandler::hashToken($token), JWTHandler::hashToken($token));
    assertNotSame($token, JWTHandler::hashToken($token));
    assertSame(64, strlen(JWTHandler::hashToken($token)));
});
