<?php

namespace App\Controllers;

use App\Utils\Response;
use App\Utils\Validator;
use App\Utils\UuidHelper;
use App\Repositories\UserRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\RefreshTokenRepository;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;
use App\Services\EmailService;

class UserController
{
    private const SELF_EDITABLE_USER_FIELDS = [
        'first_name', 'last_name', 'title', 'gender', 'date_of_birth',
        'phone_number', 'phone', 'address', 'city', 'region', 'profile_photo',
    ];

    private const SELF_EDITABLE_ROLE_FIELDS = [
        'bio', 'alternative_email', 'department', 'qualification', 'specialization',
    ];

    private const ADMIN_EDITABLE_USER_FIELDS = [
        'username', 'email', 'first_name', 'last_name', 'title', 'gender',
        'date_of_birth', 'phone_number', 'phone', 'address', 'city', 'region',
        'profile_photo', 'is_active',
    ];

    private const ADMIN_EDITABLE_ROLE_FIELDS = [
        'employee_id', 'department', 'hire_date', 'qualification', 'specialization',
        'bio', 'alternative_email', 'years_of_experience', 'employment_end_date',
        'parent_name', 'parent_phone', 'parent_email', 'emergency_contact',
        'guardian_id', 'occupation', 'prefers_email_notifications', 'prefers_sms_notifications',
    ];

    private UserRepository $userRepo;
    private NotificationRepository $notificationRepo;
    private RefreshTokenRepository $refreshTokenRepo;

    private function getDisplayName(array $user): string
    {
        $first = trim((string) ($user['first_name'] ?? ''));
        $last = trim((string) ($user['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);

        if ($full !== '') {
            return $full;
        }

        return (string) ($user['username'] ?? 'User');
    }

    private function sendUserCreatedNotification(array $recipient, array $performedBy): void
    {
        try {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $emailService = new EmailService();
            if (!$emailService->isEnabled()) {
                return;
            }

            $recipientName = $this->getDisplayName($recipient);
            $actorName = $this->getDisplayName($performedBy);
            $username = (string) ($recipient['username'] ?? '');
            $frontendUrl = rtrim((string) ($_ENV['FRONTEND_URL'] ?? 'http://localhost:8080'), '/');
            $loginUrl = $frontendUrl . '/auth/login.html';

            $subject = 'Your LMS account has been created';
            $body = "
                <p>Hi <strong>{$recipientName}</strong>,</p>
                <p>Your LMS user account has been created successfully.</p>
                <p><strong>Username:</strong> {$username}<br><strong>Email:</strong> {$email}</p>
                <p>You can sign in here: <a href=\"{$loginUrl}\">{$loginUrl}</a></p>
                <p>Created by: {$actorName}</p>
                <p style=\"font-size: 13px; color: #666;\">Tip: If you don't see this email in your inbox, check your spam or junk folder.</p>
                <p>Regards,<br>LMS Team</p>
            ";

            $altBody = "Hi {$recipientName},\n\n"
                . "Your LMS user account has been created successfully.\n"
                . "Username: {$username}\n"
                . "Email: {$email}\n"
                . "Sign in: {$loginUrl}\n"
                . "Created by: {$actorName}\n\n"
                . "If you don't see this email in your inbox, check your spam or junk folder.\n\n"
                . "Regards,\nLMS Team";

            $emailService->send($email, $subject, $body, $recipientName, $altBody);
        } catch (\Throwable $e) {
            error_log('User created email notification failed: ' . $e->getMessage());
        }
    }

    private function sendUserUpdatedNotification(array $recipient, array $performedBy, array $changedFields = []): void
    {
        try {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $emailService = new EmailService();
            if (!$emailService->isEnabled()) {
                return;
            }

            $recipientName = $this->getDisplayName($recipient);
            $actorName     = $this->getDisplayName($performedBy);

            $hidden = ['password', 'new_password', 'old_password'];
            $fields = array_values(array_filter($changedFields, function ($field) use ($hidden) {
                return !in_array((string) $field, $hidden, true);
            }));

            $emailService->sendAccountUpdatedEmail($email, $recipientName, $fields, $actorName);
        } catch (\Throwable $e) {
            error_log('User updated email notification failed: ' . $e->getMessage());
        }
    }

    private function sendUserInAppNotification(array $recipient, array $performedBy, string $action): void
    {
        try {
            $recipientId = (int) ($recipient['user_id'] ?? 0);
            if ($recipientId <= 0) {
                return;
            }

            $recipientName = $this->getDisplayName($recipient);
            $actorName = $this->getDisplayName($performedBy);
            $institutionId = (int) ($recipient['institution_id'] ?? $performedBy['institution_id'] ?? 1);

            $this->notificationRepo->create([
                'sender_id' => (int) ($performedBy['user_id'] ?? 0) ?: null,
                'institution_id' => $institutionId,
                'user_id' => $recipientId,
                'target_role' => (string) ($recipient['role'] ?? 'user'),
                'title' => 'Account ' . ($action === 'created' ? 'Created' : 'Updated'),
                'message' => 'Your account (' . $recipientName . ') was ' . $action . ' by ' . $actorName . '.',
                'notification_type' => 'user_' . $action,
                'link' => '/auth/login.html',
            ]);
        } catch (\Throwable $e) {
            error_log('User in-app notification failed: ' . $e->getMessage());
        }
    }

    private function isSuperadminUsersRequest(): bool
    {
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($uri) || $uri === '') {
            return false;
        }

        return (bool) preg_match('#/api/superadmin/users(?:/|$)#', $uri);
    }

    public function __construct()
    {
        $this->userRepo = new UserRepository();
        $this->notificationRepo = new NotificationRepository();
        $this->refreshTokenRepo = new RefreshTokenRepository();
    }

    public function index(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        // accept per_page or limit for compatibility
        $limit = isset($_GET['per_page']) ? (int) $_GET['per_page'] : (isset($_GET['limit']) ? (int) $_GET['limit'] : 10);

        // support a limited "for_chat" mode that returns chat-eligible users
        $forChat = isset($_GET['for_chat']) && (string) $_GET['for_chat'] === '1';
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        if ($forChat) {
            $institutionId = AccessControl::institutionId($user);

            // Super admins without an institution may explicitly select one;
            // never fall back to an unscoped query.
            if ($institutionId <= 0 && AccessControl::isSuperAdmin($user) && isset($_GET['institution_id'])) {
                $institutionId = (int) $_GET['institution_id'];
            }

            if ($institutionId <= 0) {
                Response::forbidden('Institution context is required');
                return;
            }

            $exclude = (int) $user['user_id'];
            $classFilter = '';
            if (isset($_GET['class']) && trim((string) $_GET['class']) !== '') {
                $classFilter = trim((string) $_GET['class']);
            } elseif (isset($_GET['class_name']) && trim((string) $_GET['class_name']) !== '') {
                $classFilter = trim((string) $_GET['class_name']);
            } elseif (isset($_GET['class_code']) && trim((string) $_GET['class_code']) !== '') {
                $classFilter = trim((string) $_GET['class_code']);
            }

            $programFilter = '';
            if (isset($_GET['program']) && trim((string) $_GET['program']) !== '') {
                $programFilter = trim((string) $_GET['program']);
            } elseif (isset($_GET['program_name']) && trim((string) $_GET['program_name']) !== '') {
                $programFilter = trim((string) $_GET['program_name']);
            } elseif (isset($_GET['program_code']) && trim((string) $_GET['program_code']) !== '') {
                $programFilter = trim((string) $_GET['program_code']);
            }
            $role = isset($_GET['role']) ? trim((string) $_GET['role']) : '';
            $excludeRole = '';
            if ($roleMiddleware->isStudent()) {
                $excludeRole = 'parent';
            }

            $candidates = $this->userRepo->getChatCandidates($institutionId, $page, $limit, $search, $exclude, $classFilter, $programFilter, $role, $excludeRole);
            $total = $this->userRepo->countChatCandidates($institutionId, $search, $exclude, $classFilter, $programFilter, $role, $excludeRole);
            Response::paginated($candidates, $total, $page, $limit);
            return;
        }

        // Super admins can access two list scopes depending on route:
        // /api/users -> admin users, /api/superadmin/users -> super_admin users.
        if (AccessControl::isSuperAdmin($user)) {
            $filters = [];
            if (isset($_GET['search']) && trim((string) $_GET['search']) !== '') {
                $filters['search'] = trim((string) $_GET['search']);
            }
            if (isset($_GET['institution_id']) && (string) $_GET['institution_id'] !== '') {
                $filters['institution_id'] = (int) $_GET['institution_id'];
            } elseif (!empty($user['institution_id'])) {
                // Keep /api/users institution-scoped by default, even for super admins.
                $filters['institution_id'] = (int) $user['institution_id'];
            }
            if (isset($_GET['is_active']) && (string) $_GET['is_active'] !== '') {
                $filters['is_active'] = (int) $_GET['is_active'];
            }

            $roleName = $this->isSuperadminUsersRequest() ? 'super_admin' : 'admin';
            $users = $this->userRepo->getByRoleFiltered($roleName, $page, $limit, $filters);
            $total = $this->userRepo->countByRoleFiltered($roleName, $filters);
            Response::paginated($users, $total, $page, $limit);
            return;
        }

        // Only admins can view full users list
        if (!AccessControl::isAdmin($user)) {
            Response::forbidden('Only admins can view users list');
            return;
        }

        $institutionId = isset($user['institution_id']) ? (int) $user['institution_id'] : 0;
        if ($institutionId <= 0) {
            Response::forbidden('Admin institution context is required');
            return;
        }

        $users = $this->userRepo->getByInstitution($institutionId, $page, $limit);
        $total = $this->userRepo->countByInstitution($institutionId);

        // Use the paginated helper to emit a consistent shape
        Response::paginated($users, $total, $page, $limit);
    }

    public function show(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canViewUser($user, $targetUser)) {
            // 404 avoids confirming that a user exists in another institution.
            Response::notFound('User not found');
            return;
        }

        Response::success($targetUser);
    }

    public function create(array $user): void
    {
        // Admins can create any users. Super admins may create only admin users.
        if (!AccessControl::isAdmin($user) && !AccessControl::isSuperAdmin($user)) {
            Response::forbidden('Only admins can create users');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::badRequest('Invalid JSON payload');
            return;
        }

        $validator = new Validator($data);
        $validator->required(['username', 'email', 'password'])
            ->email('email');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        // Check if username exists
        if ($this->userRepo->findByUsername($data['username'])) {
            Response::badRequest('Username already exists');
            return;
        }

        // Check if email exists
        if ($this->userRepo->findByEmail($data['email'])) {
            Response::badRequest('Email already exists');
            return;
        }

        $requestedSuperAdmin = !empty($data['is_super_admin']);
        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);

        // Authorise privileged account creation before writing anything. The
        // previous implementation inserted first and checked afterwards, which
        // left a working super-admin row behind even when the API returned 403.
        if ($requestedSuperAdmin && !$actorIsSuperAdmin) {
            Response::forbidden('Only super admins can create super admin users');
            return;
        }

        if (!$actorIsSuperAdmin) {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::forbidden('Admin institution context is required');
                return;
            }

            // Institution admins cannot create users in another institution or
            // create super admins, regardless of what the payload says.
            $data['institution_id'] = $institutionId;
            $data['is_super_admin'] = 0;
        }

        $userId = $this->userRepo->create($data, $actorIsSuperAdmin);

        if ($userId) {
            if ($requestedSuperAdmin) {
                $superRole = $this->userRepo->getRoleByName('super_admin');
                if ($superRole && isset($superRole['role_id'])) {
                    $this->userRepo->assignRole($userId, (int) $superRole['role_id']);
                }
            } elseif ($actorIsSuperAdmin) {
                // Super admins create admin-level accounts by default.
                $adminRole = $this->userRepo->getRoleByName('admin');
                if ($adminRole && isset($adminRole['role_id'])) {
                    $this->userRepo->assignRole($userId, (int) $adminRole['role_id']);
                }
            }

            $newUser = $this->userRepo->findById($userId);
            if (is_array($newUser)) {
                $this->sendUserCreatedNotification($newUser, $user);
                $this->sendUserInAppNotification($newUser, $user, 'created');
            }
            Response::success($newUser, 201);
        } else {
            Response::serverError('Failed to create user');
        }
    }

    public function update(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            // 404 avoids confirming that a user exists in another institution.
            Response::notFound('User not found');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::badRequest('Invalid JSON payload');
            return;
        }

        // Password changes must go through the reset-password endpoint.
        unset($data['password']);

        // Role changes must go through the role endpoints so that the same
        // authorisation and super-admin checks apply everywhere.
        if (array_key_exists('role_id', $data)) {
            Response::badRequest('Role changes must use the user role endpoint');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];
        $actorId = (int) ($user['user_id'] ?? 0);
        $isSelf = $actorId > 0 && $actorId === $targetUserId;
        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);
        $actorIsAdmin = AccessControl::isAdmin($user);

        // Regular users may edit only safe personal fields. Admins may edit
        // account fields for users they manage. Super admins may additionally
        // change is_super_admin on admin-level targets (never on themselves).
        $canUseAdminFields = !$isSelf || $actorIsAdmin || $actorIsSuperAdmin;
        $allowedUserFields = $canUseAdminFields
            ? self::ADMIN_EDITABLE_USER_FIELDS
            : self::SELF_EDITABLE_USER_FIELDS;
        $allowedRoleFields = $canUseAdminFields
            ? self::ADMIN_EDITABLE_ROLE_FIELDS
            : self::SELF_EDITABLE_ROLE_FIELDS;

        if (!$isSelf && $actorIsSuperAdmin) {
            $allowedUserFields[] = 'is_super_admin';
        }

        $allowedFields = array_merge($allowedUserFields, $allowedRoleFields);
        $unexpectedFields = array_values(array_diff(array_keys($data), $allowedFields));

        if (!empty($unexpectedFields)) {
            Response::forbidden(
                'The following fields cannot be updated: ' . implode(', ', $unexpectedFields)
            );
            return;
        }

        if (empty($data)) {
            Response::success($targetUser);
            return;
        }

        $allowPrivilegedFields = $actorIsSuperAdmin && !$isSelf;

        if ($this->userRepo->update($targetUserId, $data, $allowPrivilegedFields)) {
            $updatedUser = $this->userRepo->findByUuid($sanitizedUuid);
            if (is_array($updatedUser)) {
                $this->sendUserUpdatedNotification($updatedUser, $user, array_keys($data));
                $this->sendUserInAppNotification($updatedUser, $user, 'updated');
            }
            Response::success($updatedUser);
        } else {
            Response::serverError('Failed to update user');
        }
    }

    public function delete(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            // 404 avoids confirming that a user exists in another institution.
            Response::notFound('User not found');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];

        if ((int) ($user['user_id'] ?? 0) === $targetUserId) {
            Response::forbidden('You cannot delete your own account');
            return;
        }

        if ($this->userRepo->delete($targetUserId)) {
            // Force re-authentication everywhere for the deleted account.
            $this->refreshTokenRepo->revokeAllForUser($targetUserId);
            Response::success(['message' => 'User deleted successfully']);
        } else {
            Response::serverError('Failed to delete user');
        }
    }

    public function assignRole(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);

        if (!AccessControl::isAdmin($user) && !$actorIsSuperAdmin) {
            Response::forbidden('Only admins can assign roles');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            Response::notFound('User not found');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            Response::badRequest('Invalid JSON payload');
            return;
        }

        $validator = new Validator($data);
        $validator->required(['role_id'])->numeric('role_id');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $requestedRoleId = (int) $data['role_id'];
        $requestedRole = $this->userRepo->getRoleById($requestedRoleId);

        if (!$requestedRole || !isset($requestedRole['role_id'])) {
            Response::validationError(['role_id' => 'Selected role does not exist']);
            return;
        }

        if (!AccessControl::canAssignRole($user, $requestedRole)) {
            Response::forbidden('You cannot assign this role');
            return;
        }

        if ($this->userRepo->assignRole($targetUserId, $requestedRoleId)) {
            Response::success(['message' => 'Role assigned successfully']);
        } else {
            Response::serverError('Failed to assign role');
        }
    }

    /**
        * Assign a single role to a user.
        * Expects JSON: { roles: [<roleId>|<roleName>] }
     */
    public function assignRoles(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);

        if (!AccessControl::isAdmin($user) && !$actorIsSuperAdmin) {
            Response::forbidden('Only admins can assign roles');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            Response::notFound('User not found');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['roles']) || !is_array($data['roles'])) {
            Response::validationError(['roles' => 'roles array required']);
            return;
        }

        $roles = array_values(array_filter($data['roles'], function ($role) {
            return $role !== null && $role !== '';
        }));

        if (count($roles) !== 1) {
            Response::validationError(['roles' => 'Exactly one role can be assigned to a user']);
            return;
        }

        try {
            $roleInput = $roles[0];
            $role = null;

            if (is_numeric($roleInput) || ctype_digit((string) $roleInput)) {
                $role = $this->userRepo->getRoleById((int) $roleInput);
            } else {
                $role = $this->userRepo->getRoleByName(strtolower((string) $roleInput));
            }

            if (!$role || !isset($role['role_id'])) {
                Response::validationError(['roles' => 'Selected role does not exist']);
                return;
            }

            if (!AccessControl::canAssignRole($user, $role)) {
                Response::forbidden('You cannot assign this role');
                return;
            }

            $roleId = (int) $role['role_id'];

            if ($this->userRepo->assignRole($targetUserId, $roleId)) {
                Response::success(['message' => 'Role updated']);
                return;
            }

            Response::serverError('Failed to assign role');
        } catch (\Exception $e) {
            error_log('Assign Roles Error: ' . $e->getMessage());
            Response::serverError('Failed to assign roles');
        }
    }

    /**
     * Bulk actions on users
     * Expects JSON: { action: 'activate'|'deactivate'|'delete', ids: [1,2,3] }
     */
    public function bulk(array $user): void
    {
        $isSuperAdmin = AccessControl::isSuperAdmin($user);

        if (!AccessControl::isAdmin($user) && !$isSuperAdmin) {
            Response::forbidden('Only admins can perform bulk actions');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['action']) || !isset($data['ids']) || !is_array($data['ids'])) {
            Response::validationError(['action' => 'action and ids required']);
            return;
        }

        $action = (string) $data['action'];
        $originalIds = array_map('intval', $data['ids']);
        $ids = [];

        foreach (array_unique($originalIds) as $id) {
            if ($id <= 0 || $id === (int) ($user['user_id'] ?? 0)) {
                continue;
            }

            $target = $this->userRepo->findById($id);
            if (!$target || !AccessControl::canManageUser($user, $target)) {
                continue;
            }

            $ids[] = $id;
        }

        // Determine which ids were skipped by policy filtering.
        $skipped_ids = array_values(array_diff($originalIds, $ids));

        $results = ['updated' => 0, 'deleted' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                if ($action === 'activate') {
                    if ($this->userRepo->update($id, ['is_active' => 1]))
                        $results['updated']++;
                    else
                        $results['failed']++;
                } elseif ($action === 'deactivate') {
                    if ($this->userRepo->update($id, ['is_active' => 0])) {
                        $this->refreshTokenRepo->revokeAllForUser($id);
                        $results['updated']++;
                    } else {
                        $results['failed']++;
                    }
                } elseif ($action === 'delete') {
                    if ($this->userRepo->delete($id)) {
                        $this->refreshTokenRepo->revokeAllForUser($id);
                        $results['deleted']++;
                    } else {
                        $results['failed']++;
                    }
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                error_log('Bulk action error for user ' . $id . ': ' . $e->getMessage());
                $results['failed']++;
            }
        }

        Response::success(['results' => $results, 'skipped_ids' => $skipped_ids]);
    }

    /**
     * Import users from JSON rows
     * Expects JSON: { rows: [ { first_name, last_name, username, email, institution_name|institution_id, roles, is_active }, ... ] }
     */
    public function import(array $user): void
    {
        $isSuperAdmin = AccessControl::isSuperAdmin($user);
        // Allow admins to import. Super admins may import but only create
        // users with the 'admin' role (ignore provided roles).
        if (!AccessControl::isAdmin($user) && !$isSuperAdmin) {
            Response::forbidden('Only admins can import users');
            return;
        }

        $institutionId = AccessControl::institutionId($user);
        if (!$isSuperAdmin && $institutionId <= 0) {
            Response::forbidden('Admin institution context is required');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            Response::validationError(['rows' => 'rows array required']);
            return;
        }

        $created = 0;
        $errors = [];

        // Pre-fetch admin role id when requester is super_admin to assign later
        $adminRole = null;
        if ($isSuperAdmin) {
            $adminRole = $this->userRepo->getRoleByName('admin');
        }

        foreach ($data['rows'] as $idx => $row) {
            $rowIndex = $idx + 1;

            if (!is_array($row)) {
                $errors[] = ['row' => $rowIndex, 'error' => 'Invalid row'];
                continue;
            }

            // basic validation: require username or email
            $username = isset($row['username']) ? trim($row['username']) : null;
            $email = isset($row['email']) ? trim($row['email']) : null;

            if (empty($username) && empty($email)) {
                $errors[] = ['row' => $rowIndex, 'error' => 'Missing username or email'];
                continue;
            }

            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowIndex, 'error' => 'Invalid email'];
                continue;
            }

            $rowRoleId = null;
            if (!$isSuperAdmin && !empty($row['roles'])) {
                $rowRoles = is_array($row['roles'])
                    ? $row['roles']
                    : array_map('trim', explode(',', (string) $row['roles']));
                $rowRoles = array_values(array_filter($rowRoles, function ($role) {
                    return $role !== null && trim((string) $role) !== '';
                }));

                if (count($rowRoles) > 1) {
                    $errors[] = ['row' => $rowIndex, 'error' => 'Only one role can be assigned to a user'];
                    continue;
                }

                if (count($rowRoles) === 1) {
                    $roleInput = $rowRoles[0];

                    if (is_numeric($roleInput) || ctype_digit((string) $roleInput)) {
                        $role = $this->userRepo->getRoleById((int) $roleInput);
                    } else {
                        $role = $this->userRepo->getRoleByName(strtolower((string) $roleInput));
                    }

                    if (!$role || !isset($role['role_id'])) {
                        $errors[] = ['row' => $rowIndex, 'error' => 'Selected role does not exist'];
                        continue;
                    }

                    if (!AccessControl::canAssignRole($user, $role)) {
                        $errors[] = ['row' => $rowIndex, 'error' => 'You cannot assign this role'];
                        continue;
                    }

                    $rowRoleId = (int) $role['role_id'];
                }
            }

            // Prepare payload for creation
            $payload = [];
            $payload['first_name'] = $row['first_name'] ?? null;
            $payload['last_name'] = $row['last_name'] ?? null;
            $payload['username'] = $username ?? ($email ? preg_replace('/@.+$/', '', $email) : null);
            $payload['email'] = $email ?? null;
            // auto generate a password if not provided
            $payload['password'] = $row['password'] ?? bin2hex(random_bytes(6));
            if ($isSuperAdmin) {
                // attempt to resolve institution id by name if provided
                if (!empty($row['institution_id'])) {
                    $payload['institution_id'] = (int) $row['institution_id'];
                } elseif (!empty($row['institution_name'])) {
                    $instRepo = new \App\Repositories\InstitutionRepository();
                    $inst = $instRepo->findByName($row['institution_name']);
                    if ($inst && isset($inst['institution_id'])) {
                        $payload['institution_id'] = (int) $inst['institution_id'];
                    }
                }
            }

            // Never trust a row-provided institution or super-admin flag for
            // institution admins.
            if (!$isSuperAdmin) {
                $payload['institution_id'] = $institutionId;
            }
            $payload['is_super_admin'] = 0;

            // default active flag
            $payload['is_active'] = isset($row['is_active']) ? (int) $row['is_active'] : 1;

            // Skip duplicates before hitting DB unique constraints.
            if (!empty($payload['username']) && $this->userRepo->isUsernameTaken((string) $payload['username'], $payload['institution_id'] ?? null)) {
                $errors[] = ['row' => $rowIndex, 'error' => 'User already exists (username already used in this institution)'];
                continue;
            }

            if (!empty($payload['email']) && $this->userRepo->isEmailTaken((string) $payload['email'])) {
                $errors[] = ['row' => $rowIndex, 'error' => 'User already exists (email already used)'];
                continue;
            }

            // create user
            $userId = $this->userRepo->create($payload, false);
            if (!$userId) {
                // Fallback classification in case a race condition still causes duplicate insert failure.
                if (!empty($payload['username']) && $this->userRepo->isUsernameTaken((string) $payload['username'], $payload['institution_id'] ?? null)) {
                    $errors[] = ['row' => $rowIndex, 'error' => 'User already exists (username already used in this institution)'];
                } elseif (!empty($payload['email']) && $this->userRepo->isEmailTaken((string) $payload['email'])) {
                    $errors[] = ['row' => $rowIndex, 'error' => 'User already exists (email already used)'];
                } else {
                    $errors[] = ['row' => $rowIndex, 'error' => 'Failed to create user'];
                }
                continue;
            }

            $created++;

            // assign roles
            if ($isSuperAdmin) {
                // When super_admin imports, always assign 'admin' role and ignore provided roles
                if ($adminRole && isset($adminRole['role_id'])) {
                    $this->userRepo->assignRole($userId, (int) $adminRole['role_id']);
                }
            } elseif ($rowRoleId !== null) {
                $this->userRepo->assignRole($userId, $rowRoleId);
            }

            $createdUser = $this->userRepo->findById((int) $userId);
            if (is_array($createdUser)) {
                $this->sendUserCreatedNotification($createdUser, $user);
                $this->sendUserInAppNotification($createdUser, $user, 'created');
            }
        }

        // Provide a list of skipped row numbers for the client UI to display.
        $skipped_rows = array_map(function ($e) {
            return $e['row'] ?? null;
        }, $errors);

        Response::success(['created' => $created, 'errors' => $errors, 'skipped_rows' => array_values(array_filter($skipped_rows))]);
    }

    public function removeRole(array $user, string $uuid, int $roleId): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);

        if (!AccessControl::isAdmin($user) && !$actorIsSuperAdmin) {
            Response::forbidden('Only admins can remove roles');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            Response::notFound('User not found');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];

        $role = $this->userRepo->getRoleById($roleId);
        if (!$role || !isset($role['role_id'])) {
            Response::validationError(['role_id' => 'Selected role does not exist']);
            return;
        }

        // Super-admin role management is restricted to the dedicated flow.
        if (AccessControl::isSuperAdminRoleName((string) ($role['role_name'] ?? ''))) {
            Response::forbidden('Superadmin role cannot be removed here');
            return;
        }

        if ($this->userRepo->removeRole($targetUserId, $roleId)) {
            Response::success(['message' => 'Role removed successfully']);
        } else {
            Response::serverError('Failed to remove role');
        }
    }

    public function resetPassword(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $actorIsSuperAdmin = AccessControl::isSuperAdmin($user);

        if (!AccessControl::isAdmin($user) && !$actorIsSuperAdmin) {
            Response::forbidden('Only admins can reset user passwords');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);
        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canManageUser($user, $targetUser)) {
            // 404 avoids confirming that a user exists in another institution.
            Response::notFound('User not found');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            Response::badRequest('Invalid JSON payload');
            return;
        }

        $validator = new Validator($data);
        $validator->required(['new_password'])->min('new_password', 8);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if ($this->userRepo->updatePassword((int) $targetUser['user_id'], (string) $data['new_password'])) {
            // A password reset invalidates every existing refresh token.
            $this->refreshTokenRepo->revokeAllForUser((int) $targetUser['user_id']);

            $this->userRepo->logActivity((int) $user['user_id'], 'admin_password_reset', [
                'target_user_uuid' => $sanitizedUuid,
                'target_user_id' => (int) $targetUser['user_id'],
            ]);

            $email = trim((string) ($targetUser['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailService = new EmailService();
                if ($emailService->isEnabled()) {
                    $emailService->sendPasswordChangedConfirmationEmail(
                        $email,
                        $this->getDisplayName($targetUser),
                        $this->getDisplayName($user)
                    );
                }
            }

            Response::success(['message' => 'Password reset successfully']);
            return;
        }

        Response::serverError('Failed to reset password');
    }

    public function getActivity(array $user, string $uuid): void
    {
        $sanitizedUuid = UuidHelper::sanitize($uuid);
        if (!$sanitizedUuid) {
            Response::badRequest('Invalid UUID format');
            return;
        }

        $targetUser = $this->userRepo->findByUuid($sanitizedUuid);

        if (!$targetUser) {
            Response::notFound('User not found');
            return;
        }

        if (!AccessControl::canViewUser($user, $targetUser)) {
            // 404 avoids confirming that a user exists in another institution.
            Response::notFound('User not found');
            return;
        }

        $targetUserId = (int) $targetUser['user_id'];

        try {
            $db = \App\Config\Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM user_activity 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute(['user_id' => $targetUserId]);
            $activity = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            Response::success($activity);
        } catch (\PDOException $e) {
            error_log("Get User Activity Error: " . $e->getMessage());
            Response::serverError('Failed to fetch user activity');
        }
    }
}