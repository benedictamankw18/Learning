<?php

namespace App\Middleware;

use App\Utils\Response;

class RoleMiddleware
{
    private array $user;

    public function __construct(array $user)
    {
        $this->user = $user;
    }

    public function hasRole(string|array $roles): bool
    {
        $requiredRoles = is_array($roles) ? $roles : [$roles];

        $userRoles = $this->user['roles'] ?? [];
        if (!is_array($userRoles)) {
            $userRoles = [$userRoles];
        }

        // AuthMiddleware exposes both the legacy singular role and the roles
        // array. Consider both so super-admin-only accounts are not denied.
        if (isset($this->user['role'])) {
            $userRoles[] = (string) $this->user['role'];
        }

        $isSuperAdmin = !empty($this->user['is_super_admin'])
            || in_array('super_admin', $userRoles, true);

        foreach ($requiredRoles as $role) {
            $role = (string) $role;

            if (in_array($role, $userRoles, true)) {
                return true;
            }

            // A super admin satisfies admin-level gates but not role-specific
            // gates such as teacher or student.
            if ($role === 'admin' && $isSuperAdmin) {
                return true;
            }

            if ($role === 'super_admin' && $isSuperAdmin) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string|array $permissions): bool
    {
        $requiredPermissions = is_array($permissions) ? $permissions : [$permissions];
        $userPermissions = $this->user['permissions'] ?? [];

        foreach ($requiredPermissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return true;
            }
        }

        return false;
    }

    public function requireRole(string|array $roles): bool
    {
        if (!$this->hasRole($roles)) {
            $roleList = is_array($roles) ? implode(', ', $roles) : $roles;
            Response::forbidden("Required role(s): {$roleList}");
            return false;
        }
        return true;
    }

    public function requirePermission(string|array $permissions): bool
    {
        if (!$this->hasPermission($permissions)) {
            $permissionList = is_array($permissions) ? implode(', ', $permissions) : $permissions;
            Response::forbidden("Required permission(s): {$permissionList}");
            return false;
        }
        return true;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isTeacher(): bool
    {
        return $this->hasRole('teacher');
    }

    public function isStudent(): bool
    {
        return $this->hasRole('student');
    }

    public function isParent(): bool
    {
        return $this->hasRole('parent');
    }
}
