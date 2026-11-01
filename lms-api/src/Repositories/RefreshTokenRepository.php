<?php

namespace App\Repositories;

use App\Config\Database;
use App\Utils\JWTHandler;
use PDO;

/**
 * Persists refresh tokens so they can be rotated and revoked.
 *
 * Only a SHA-256 hash of each token is stored; the raw token never touches the
 * database.
 */
class RefreshTokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(
        int $userId,
        string $token,
        string $jti,
        int $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): bool {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO refresh_tokens
                    (user_id, jti, token_hash, expires_at, user_agent, ip_address)
                 VALUES
                    (:user_id, :jti, :token_hash, :expires_at, :user_agent, :ip_address)'
            );

            return $stmt->execute([
                'user_id' => $userId,
                'jti' => $jti,
                'token_hash' => JWTHandler::hashToken($token),
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'user_agent' => $userAgent,
                'ip_address' => $ipAddress,
            ]);
        } catch (\PDOException $e) {
            error_log('Refresh token create error: ' . $e->getMessage());
            return false;
        }
    }

    public function findByJti(string $jti): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM refresh_tokens WHERE jti = :jti LIMIT 1'
            );
            $stmt->execute(['jti' => $jti]);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('Refresh token lookup error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Revoke the old token and persist the replacement atomically.
     */
    public function rotate(
        int $id,
        string $newToken,
        string $newJti,
        int $newExpiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): bool {
        try {
            $this->db->beginTransaction();

            $revokeStmt = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW(), replaced_by_jti = :replaced_by_jti
                 WHERE id = :id AND revoked_at IS NULL'
            );
            $revokeStmt->execute([
                'id' => $id,
                'replaced_by_jti' => $newJti,
            ]);

            if ($revokeStmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $insertStmt = $this->db->prepare(
                'INSERT INTO refresh_tokens
                    (user_id, jti, token_hash, expires_at, user_agent, ip_address)
                 SELECT user_id, :jti, :token_hash, :expires_at, :user_agent, :ip_address
                 FROM refresh_tokens WHERE id = :id'
            );
            $insertStmt->execute([
                'id' => $id,
                'jti' => $newJti,
                'token_hash' => JWTHandler::hashToken($newToken),
                'expires_at' => date('Y-m-d H:i:s', $newExpiresAt),
                'user_agent' => $userAgent,
                'ip_address' => $ipAddress,
            ]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Refresh token rotate error: ' . $e->getMessage());
            return false;
        }
    }

    public function revokeByToken(string $token): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW()
                 WHERE token_hash = :token_hash AND revoked_at IS NULL'
            );

            return $stmt->execute(['token_hash' => JWTHandler::hashToken($token)]);
        } catch (\PDOException $e) {
            error_log('Refresh token revoke error: ' . $e->getMessage());
            return false;
        }
    }

    public function revokeAllForUser(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = NOW()
                 WHERE user_id = :user_id AND revoked_at IS NULL'
            );

            return $stmt->execute(['user_id' => $userId]);
        } catch (\PDOException $e) {
            error_log('Refresh token revoke-all error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove tokens that expired more than $days ago. Intended for a cron job.
     */
    public function deleteExpired(int $days = 30): int
    {
        try {
            $days = max(1, $days);
            $stmt = $this->db->prepare(
                "DELETE FROM refresh_tokens
                 WHERE expires_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            );
            $stmt->execute();

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log('Refresh token cleanup error: ' . $e->getMessage());
            return 0;
        }
    }
}
