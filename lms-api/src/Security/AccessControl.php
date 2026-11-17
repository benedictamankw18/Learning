<?php

namespace App\Security;

/**
 * Pure authorization policy helpers.
 *
 * These methods do not emit HTTP responses. Callers decide whether a denial
 * should be a 403 or a 404, which lets controllers avoid confirming that a
 * resource exists in another institution.
 */
final class AccessControl
{
    public static function isSuperAdmin(array $actor): bool
    {
        if (!empty($actor['is_super_admin'])) {
            return true;
        }

        if (isset($actor['role']) && self::isSuperAdminRoleName((string) $actor['role'])) {
            return true;
        }

        foreach (self::roles($actor) as $role) {
            if (self::isSuperAdminRoleName($role)) {
                return true;
            }
        }

        return false;
    }

    public static function isAdmin(array $actor): bool
    {
        return self::hasRole($actor, 'admin');
    }

    public static function hasRole(array $actor, string|array $roles): bool
    {
        $requiredRoles = is_array($roles) ? $roles : [$roles];
        $actorRoles = self::roles($actor);

        if (isset($actor['role'])) {
            $actorRoles[] = (string) $actor['role'];
        }

        foreach ($requiredRoles as $role) {
            if (in_array((string) $role, $actorRoles, true)) {
                return true;
            }
        }

        return false;
    }

    public static function sameInstitution(array $actor, array $target): bool
    {
        $actorInstitution = self::institutionId($actor);
        $targetInstitution = self::institutionId($target);

        return $actorInstitution > 0
            && $targetInstitution > 0
            && $actorInstitution === $targetInstitution;
    }

    public static function institutionId(array $actor): int
    {
        return (int) ($actor['institution_id'] ?? 0);
    }

    public static function targetIsSuperAdmin(array $target): bool
    {
        if (!empty($target['is_super_admin'])) {
            return true;
        }

        if (isset($target['role']) && self::isSuperAdminRoleName((string) $target['role'])) {
            return true;
        }

        foreach (self::roles($target) as $role) {
            if (self::isSuperAdminRoleName($role)) {
                return true;
            }
        }

        return false;
    }

    public static function canViewUser(array $actor, array $target): bool
    {
        $actorId = (int) ($actor['user_id'] ?? 0);
        $targetId = (int) ($target['user_id'] ?? 0);

        if ($actorId > 0 && $actorId === $targetId) {
            return true;
        }

        // Super admins have global visibility. Management is restricted below.
        if (self::isSuperAdmin($actor)) {
            return true;
        }

        return self::isAdmin($actor)
            && !self::targetIsSuperAdmin($target)
            && self::sameInstitution($actor, $target);
    }

    public static function canManageUser(array $actor, array $target): bool
    {
        $actorId = (int) ($actor['user_id'] ?? 0);
        $targetId = (int) ($target['user_id'] ?? 0);

        if ($actorId > 0 && $actorId === $targetId) {
            return true;
        }

        // Super admins may manage admin-level and super-admin accounts. They
        // cannot manage ordinary users through the user-management endpoints.
        if (self::isSuperAdmin($actor)) {
            return self::hasAdminLikeRole($target) || self::targetIsSuperAdmin($target);
        }

        return self::isAdmin($actor)
            && !self::targetIsSuperAdmin($target)
            && self::sameInstitution($actor, $target);
    }

    public static function canAssignRole(array $actor, array $requestedRole): bool
    {
        $roleName = strtolower((string) ($requestedRole['role_name'] ?? ''));

        // Super-admin assignment is reserved for the dedicated create flow.
        if (self::isSuperAdminRoleName($roleName)) {
            return false;
        }

        if (!self::isSuperAdmin($actor)) {
            return self::isAdmin($actor);
        }

        // A super-admin-only account may assign admin-level roles. If the actor
        // also holds the admin role, the normal admin rules apply.
        return self::isAdmin($actor) || self::isAdminLikeRoleName($roleName);
    }

    public static function hasAdminLikeRole(array $user): bool
    {
        foreach (self::roles($user) as $role) {
            if (self::isAdminLikeRoleName($role)) {
                return true;
            }
        }

        if (isset($user['role']) && self::isAdminLikeRoleName((string) $user['role'])) {
            return true;
        }

        return false;
    }

    public static function isAdminLikeRoleName(string $roleName): bool
    {
        $normalized = self::normalizeRoleName($roleName);

        return $normalized !== 'superadmin' && str_contains($normalized, 'admin');
    }

    public static function isSuperAdminRoleName(string $roleName): bool
    {
        return self::normalizeRoleName($roleName) === 'superadmin';
    }

    public static function normalizeRoleName(string $roleName): string
    {
        return str_replace(['_', '-', ' '], '', strtolower(trim($roleName)));
    }

    /**
     * @return array<int, string>
     */
    private static function roles(array $user): array
    {
        $roles = $user['roles'] ?? [];

        if (!is_array($roles)) {
            $roles = [$roles];
        }

        return array_values(array_map('strval', $roles));
    }
}
