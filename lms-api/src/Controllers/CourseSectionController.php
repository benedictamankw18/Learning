<?php

namespace App\Controllers;

use App\Repositories\CourseSectionRepository;
use App\Repositories\ClassSubjectRepository;
use App\Utils\Response;
use App\Utils\Validator;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;

class CourseSectionController
{
    private CourseSectionRepository $repo;
    private ClassSubjectRepository $courseRepo;

    public function __construct()
    {
        $this->repo = new CourseSectionRepository();
        $this->courseRepo = new ClassSubjectRepository();
    }

    /**
     * Get all sections for a course
     * GET /courses/{courseId}/sections
     */
    public function index(array $user, int $courseId): void
    {
        // Check if course exists and user has access
        $course = $this->courseRepo->findById($courseId);

        if (!$course) {
            Response::error('Course not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Course not found', 404);
            return;
        }

        $sections = $this->repo->getCourseSections($courseId);

        Response::success([
            'sections' => $sections,
            'count' => count($sections)
        ]);
    }

    /**
     * Create a new section (teacher/admin)
     * POST /courses/{courseId}/sections
     */
    public function create(array $user, int $courseId): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if course exists
        $course = $this->courseRepo->findById($courseId);

        if (!$course) {
            Response::error('Course not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Course not found', 404);
            return;
        }

        // Teachers can only create sections for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacherId = $user['teacher_id'] ?? null;
            if ($teacherId === null) {
                Response::forbidden('Teacher profile is not linked to this user account');
                return;
            }

            if ((int) ($course['teacher_id'] ?? 0) !== (int) $teacherId) {
                Response::error('Course not found', 404);
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['section_name'])
            ->max('section_name', 100)
            ->numeric('order_index')
            ->numeric('is_active');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $data['course_id'] = $courseId;
        $data['created_by'] = $user['user_id'];
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = ((int) $data['is_active'] === 1) ? 1 : 0;
        }

        $sectionId = $this->repo->create($data);

        Response::success([
            'message' => 'Section created successfully',
            'section_id' => $sectionId
        ], 201);
    }

    /**
     * Update a section (teacher/admin)
     * PUT /courses/{courseId}/sections/{sectionId}
     */
    public function update(array $user, int $courseId, int $sectionId): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if section exists
        $section = $this->repo->findById($sectionId);

        if (!$section) {
            Response::error('Section not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $section['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Section not found', 404);
            return;
        }

        // Verify section belongs to course
        if ((int) $section['course_id'] !== $courseId) {
            Response::error('Section does not belong to this course', 400);
            return;
        }

        // Teachers can only update sections for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $course = $this->courseRepo->findById($courseId);
            if (!$course) {
                Response::error('Section not found', 404);
                return;
            }

            $teacherId = $user['teacher_id'] ?? null;
            if ($teacherId === null) {
                Response::forbidden('Teacher profile is not linked to this user account');
                return;
            }

            if ((int) ($course['teacher_id'] ?? 0) !== (int) $teacherId) {
                Response::error('Section not found', 404);
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->max('section_name', 100)
            ->numeric('order_index')
            ->numeric('is_active');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = ((int) $data['is_active'] === 1) ? 1 : 0;
        }

        $this->repo->update($sectionId, $data);

        Response::success(['message' => 'Section updated successfully']);
    }

    /**
     * Delete a section (teacher/admin)
     * DELETE /courses/{courseId}/sections/{sectionId}
     */
    public function delete(array $user, int $courseId, int $sectionId): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if section exists
        $section = $this->repo->findById($sectionId);

        if (!$section) {
            Response::error('Section not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $section['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Section not found', 404);
            return;
        }

        // Verify section belongs to course
        if ((int) $section['course_id'] !== $courseId) {
            Response::error('Section does not belong to this course', 400);
            return;
        }

        // Teachers can only delete sections for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $course = $this->courseRepo->findById($courseId);
            if (!$course) {
                Response::error('Section not found', 404);
                return;
            }

            $teacherId = $user['teacher_id'] ?? null;
            if ($teacherId === null) {
                Response::forbidden('Teacher profile is not linked to this user account');
                return;
            }

            if ((int) ($course['teacher_id'] ?? 0) !== (int) $teacherId) {
                Response::error('Section not found', 404);
                return;
            }
        }

        $this->repo->delete($sectionId);

        Response::success(['message' => 'Section deleted successfully']);
    }
}
