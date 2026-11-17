<?php

namespace App\Controllers;

use App\Utils\Response;
use App\Utils\Validator;
use App\Repositories\GradeScaleRepository;
use App\Repositories\GradeCategoryRepository;
use App\Repositories\NotificationRepository;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;

class GradeScaleController
{
    private GradeScaleRepository $repo;
    private NotificationRepository $notificationRepo;
    private GradeCategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->repo = new GradeScaleRepository();
        $this->notificationRepo = new NotificationRepository();
        $this->categoryRepo = new GradeCategoryRepository();
    }

    public function index(array $user): void
    {
        $institutionId = null;
        if (!AccessControl::isSuperAdmin($user)) {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::forbidden('Institution context is required');
                return;
            }
        }

        $scales = $this->repo->getAll($institutionId);
        Response::success($scales);
    }

    public function show(array $user, int $id): void
    {
        $institutionId = null;
        if (!AccessControl::isSuperAdmin($user)) {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::forbidden('Institution context is required');
                return;
            }
        }

        $scale = $this->repo->findById($id, $institutionId);

        if (!$scale) {
            Response::notFound('Grade scale not found');
            return;
        }

        Response::success($scale);
    }

    public function create(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['grade', 'min_score', 'max_score'])
            ->maxLength('grade', 10)
            ->numeric('min_score')
            ->numeric('max_score');

        if (isset($data['grade_point'])) {
            $validator->numeric('grade_point');
        }

        if (isset($data['grade_categories_id'])) {
            $validator->numeric('grade_categories_id');
        }

        if (isset($data['Interpretation'])) {
            $validator->maxLength('Interpretation', 100);
        }

        if (isset($data['Status'])) {
            $validator->maxLength('Status', 20);
        }

        if (isset($data['remark'])) {
            $validator->maxLength('remark', 255);
        }

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (!AccessControl::isSuperAdmin($user)) {
            $data['institution_id'] = AccessControl::institutionId($user);
        } else {
            if (isset($data['institution_id'])) {
                $data['institution_id'] = (int) $data['institution_id'];
            }
            if (empty($data['institution_id']) && AccessControl::institutionId($user) > 0) {
                $data['institution_id'] = AccessControl::institutionId($user);
            }
        }

        if (empty($data['institution_id'])) {
            Response::badRequest('institution_id is required');
            return;
        }

        if (!empty($data['grade_categories_id'])) {
            $categoryId = (int) $data['grade_categories_id'];
            if ($categoryId <= 0
                || !$this->categoryRepo->findById($categoryId, (int) $data['institution_id'])) {
                Response::badRequest('Invalid grade_categories_id for this institution');
                return;
            }
        }

        $scaleId = $this->repo->create($data);

        if ($scaleId) {
            try {
                $institutionId = (int) ($data['institution_id'] ?? ($user['institution_id'] ?? 0));
                $this->notificationRepo->create([
                    'sender_id' => (int) ($user['user_id'] ?? 0) ?: null,
                    'institution_id' => $institutionId,
                    'target_role' => 'admin',
                    'title' => 'Grade Scale Created',
                    'message' => 'A grade scale was created.',
                    'notification_type' => 'grade_scale_created',
                    'link' => '/admin/dashboard.html#management-grades',
                ]);
            } catch (\Throwable $e) {
                error_log('GradeScaleController::notify create ' . $e->getMessage());
            }
            Response::success([
                'message' => 'Grade scale created successfully',
                'grade_scale_id' => $scaleId
            ], 201);
        } else {
            Response::serverError('Failed to create grade scale');
        }
    }

    public function update(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $institutionId = null;
        if (!AccessControl::isSuperAdmin($user)) {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::forbidden('Institution context is required');
                return;
            }
        }

        $scale = $this->repo->findById($id, $institutionId);

        if (!$scale) {
            Response::notFound('Grade scale not found');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        if (isset($data['grade'])) {
            $validator->maxLength('grade', 10);
        }
        if (isset($data['min_score'])) {
            $validator->numeric('min_score');
        }
        if (isset($data['max_score'])) {
            $validator->numeric('max_score');
        }
        if (isset($data['grade_point'])) {
            $validator->numeric('grade_point');
        }

        if (isset($data['grade_categories_id'])) {
            $validator->numeric('grade_categories_id');
        }

        if (isset($data['Interpretation'])) {
            $validator->maxLength('Interpretation', 100);
        }

        if (isset($data['Status'])) {
            $validator->maxLength('Status', 20);
        }

        if (isset($data['remark'])) {
            $validator->maxLength('remark', 255);
        }

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('grade_categories_id', $data)
            && $data['grade_categories_id'] !== null
            && $data['grade_categories_id'] !== '') {
            $categoryId = (int) $data['grade_categories_id'];
            if ($categoryId <= 0
                || !$this->categoryRepo->findById($categoryId, (int) $scale['institution_id'])) {
                Response::badRequest('Invalid grade_categories_id for this institution');
                return;
            }
        }

        if ($this->repo->update($id, $data)) {
            try {
                $institutionId = (int) ($scale['institution_id'] ?? ($user['institution_id'] ?? 0));
                $this->notificationRepo->create([
                    'sender_id' => (int) ($user['user_id'] ?? 0) ?: null,
                    'institution_id' => $institutionId,
                    'target_role' => 'admin',
                    'title' => 'Grade Scale Updated',
                    'message' => 'A grade scale was updated.',
                    'notification_type' => 'grade_scale_updated',
                    'link' => '/admin/dashboard.html#management-grades',
                ]);
            } catch (\Throwable $e) {
                error_log('GradeScaleController::notify update ' . $e->getMessage());
            }
            Response::success(['message' => 'Grade scale updated successfully']);
        } else {
            Response::serverError('Failed to update grade scale');
        }
    }

    public function delete(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'super_admin'])) {
            return;
        }

        $institutionId = null;
        if (!AccessControl::isSuperAdmin($user)) {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::forbidden('Institution context is required');
                return;
            }
        }

        $scale = $this->repo->findById($id, $institutionId);

        if (!$scale) {
            Response::notFound('Grade scale not found');
            return;
        }

        if ($this->repo->delete($id, $institutionId)) {
            Response::success(['message' => 'Grade scale deleted successfully']);
        } else {
            Response::serverError('Failed to delete grade scale');
        }
    }
}