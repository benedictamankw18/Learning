<?php

namespace App\Controllers;

use App\Repositories\LoginActivityRepository;
use App\Repositories\UserRepository;
use App\Utils\Response;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;
class LoginActivityController
{
    private $repo;
    private $userRepo;

    public function __construct()
    {
        $this->repo = new LoginActivityRepository();
        $this->userRepo = new UserRepository();
    }

    /**
     * Get all login activities (Admin only)
     * GET /login-activity
     */
    public function index(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        $filters = [];
        if (isset($_GET['user_id'])) {
            $targetUserId = (int) $_GET['user_id'];
            if (!AccessControl::isSuperAdmin($user)) {
                $targetUser = $this->userRepo->findById($targetUserId);
                if (!$targetUser || AccessControl::institutionId($targetUser) !== AccessControl::institutionId($user)) {
                    Response::notFound('User not found');
                    return;
                }
            }
            $filters['user_id'] = $targetUserId;
        }
        if (isset($_GET['is_successful'])) {
            $filters['is_successful'] = (int) $_GET['is_successful'];
        }
        if (isset($_GET['from_date'])) {
            $filters['from_date'] = $_GET['from_date'];
        }
        if (isset($_GET['to_date'])) {
            $filters['to_date'] = $_GET['to_date'];
        }

        if (!AccessControl::isSuperAdmin($user)) {
            $filters['institution_id'] = AccessControl::institutionId($user);
        } elseif (isset($_GET['institution_id']) && $_GET['institution_id'] !== '') {
            $filters['institution_id'] = (int) $_GET['institution_id'];
        }

        $activities = $this->repo->getAll($page, $limit, $filters);
        $total = $this->repo->count($filters);

        Response::success([
            'data' => $activities,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Get my login history
     * GET /login-activity/my-history
     */
    public function getMyHistory(array $user): void
    {
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        $activities = $this->repo->getUserLoginHistory($user['user_id'], $page, $limit);
        $total = $this->repo->countUserLoginActivity($user['user_id']);

        Response::success([
            'data' => $activities,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Get specific user's login history (Admin only)
     * GET /users/{id}/login-activity
     */
    public function getUserHistory(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        if (!AccessControl::isSuperAdmin($user)) {
            $targetUser = $this->userRepo->findById($id);
            if (!$targetUser || AccessControl::institutionId($targetUser) !== AccessControl::institutionId($user)) {
                Response::notFound('User not found');
                return;
            }
        }

        $activities = $this->repo->getUserLoginHistory($id, $page, $limit);
        $total = $this->repo->countUserLoginActivity($id);

        Response::success([
            'data' => $activities,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Get recent logins
     * GET /login-activity/recent
     */
    public function getRecent(array $user): void
    {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
        $activities = $this->repo->getRecentLogins($user['user_id'], $limit);

        Response::success(['data' => $activities]);
    }

    /**
     * Get failed login attempts (Admin only)
     * GET /login-activity/failed
     */
    public function getFailedAttempts(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 24;
        $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

        if ($userId !== null && !AccessControl::isSuperAdmin($user)) {
            $targetUser = $this->userRepo->findById($userId);
            if (!$targetUser || AccessControl::institutionId($targetUser) !== AccessControl::institutionId($user)) {
                Response::notFound('User not found');
                return;
            }
        }

        $institutionId = AccessControl::isSuperAdmin($user) && isset($_GET['institution_id']) && $_GET['institution_id'] !== ''
            ? (int) $_GET['institution_id']
            : (AccessControl::isSuperAdmin($user) ? null : AccessControl::institutionId($user));

        $activities = $this->repo->getFailedAttempts($userId, $hours, $institutionId);

        Response::success(['data' => $activities]);
    }
}
