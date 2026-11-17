<?php

namespace App\Controllers;

use App\Utils\Response;
use App\Utils\Validator;
use App\Repositories\CourseRepository;
use App\Repositories\CourseReviewRepository;
use App\Repositories\CourseScheduleRepository;
use App\Repositories\CourseMaterialRepository;
use App\Repositories\ClassRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\AcademicYearRepository;
use App\Repositories\SemesterRepository;
use App\Repositories\TeacherRepository;
use App\Repositories\StudentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\InstitutionRepository;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;

class CourseController
{
    private CourseRepository $courseRepo;
    private CourseReviewRepository $reviewRepo;
    private CourseScheduleRepository $scheduleRepo;
    private CourseMaterialRepository $materialRepo;
    private ClassRepository $classRepo;
    private SubjectRepository $subjectRepo;
    private AcademicYearRepository $academicYearRepo;
    private SemesterRepository $semesterRepo;
    private TeacherRepository $teacherRepo;
    private StudentRepository $studentRepo;
    private NotificationRepository $notificationRepo;
    private InstitutionRepository $institutionRepo;

    public function __construct()
    {
        $this->courseRepo = new CourseRepository();
        $this->reviewRepo = new CourseReviewRepository();
        $this->scheduleRepo = new CourseScheduleRepository();
        $this->materialRepo = new CourseMaterialRepository();
        $this->classRepo = new ClassRepository();
        $this->subjectRepo = new SubjectRepository();
        $this->academicYearRepo = new AcademicYearRepository();
        $this->semesterRepo = new SemesterRepository();
        $this->teacherRepo = new TeacherRepository();
        $this->studentRepo = new StudentRepository();
        $this->notificationRepo = new NotificationRepository();
        $this->institutionRepo = new InstitutionRepository();
    }

    /**
     * Ensure class, subject, teacher, academic year, and semester references
     * all belong to the target institution.
     *
     * @return string|null Error message, or null when valid.
     */
    private function validateCourseReferences(array $data, int $institutionId): ?string
    {
        if (isset($data['class_id'])) {
            $class = $this->classRepo->findById((int) $data['class_id']);
            if (!$class) {
                return 'Selected class does not exist';
            }
            if ($institutionId > 0 && (int) ($class['institution_id'] ?? 0) !== $institutionId) {
                return 'Selected class belongs to another institution';
            }
        }

        if (isset($data['subject_id'])) {
            $subject = $this->subjectRepo->findById((int) $data['subject_id']);
            if (!$subject) {
                return 'Selected subject does not exist';
            }
            if ($institutionId > 0 && (int) ($subject['institution_id'] ?? 0) !== $institutionId) {
                return 'Selected subject belongs to another institution';
            }
        }

        if (!empty($data['teacher_id'])) {
            $teacher = $this->teacherRepo->findById((int) $data['teacher_id']);
            if (!$teacher) {
                return 'Selected teacher does not exist';
            }
            if ($institutionId > 0 && (int) ($teacher['institution_id'] ?? 0) !== $institutionId) {
                return 'Selected teacher belongs to another institution';
            }
        }

        if (!empty($data['academic_year_id'])) {
            $year = $this->academicYearRepo->findById(
                (int) $data['academic_year_id'],
                $institutionId > 0 ? $institutionId : null
            );
            if (!$year) {
                return 'Selected academic year is invalid for this institution';
            }
        }

        if (!empty($data['semester_id'])) {
            $semester = $this->semesterRepo->findById((int) $data['semester_id']);
            if (!$semester) {
                return 'Selected semester does not exist';
            }
            if ($institutionId > 0 && (int) ($semester['institution_id'] ?? 0) !== $institutionId) {
                return 'Selected semester belongs to another institution';
            }
        }

        return null;
    }

    private function notifyCourseMaterialChanged(array $course, string $action, array $actor): void
    {
        try {
            $courseId = (int) ($course['course_id'] ?? 0);
            $institutionId = (int) ($course['institution_id'] ?? 0);
            if ($courseId <= 0 || $institutionId <= 0) {
                return;
            }

            $courseName = trim((string) (($course['class_name'] ?? '') . ' ' . ($course['subject_name'] ?? '')));
            if ($courseName === '') {
                $courseName = 'class subject';
            }

            $title = 'Course Material ' . ($action === 'created' ? 'Created' : 'Updated');
            $message = 'Course material was ' . $action . ' for ' . $courseName . ' (ID: ' . $courseId . ').';
            $senderId = (int) ($actor['user_id'] ?? 0);

            $students = $this->courseRepo->getEnrolledStudents($courseId, 1, 5000);
            foreach ($students as $student) {
                $studentUserId = (int) ($student['user_id'] ?? 0);
                if ($studentUserId <= 0) {
                    continue;
                }

                $this->notificationRepo->create([
                    'sender_id' => $senderId > 0 ? $senderId : null,
                    'institution_id' => $institutionId,
                    'user_id' => $studentUserId,
                    'target_role' => 'student',
                    'course_id' => $courseId,
                    'title' => $title,
                    'message' => $message,
                    'notification_type' => 'course_material_' . $action,
                    'link' => '/student/dashboard.html#course-materials',
                ]);
            }

            $teacherId = (int) ($course['teacher_id'] ?? 0);
            if ($teacherId > 0) {
                $teacher = $this->teacherRepo->findById($teacherId);
                $teacherUserId = (int) ($teacher['user_id'] ?? 0);
                if ($teacherUserId > 0) {
                    $this->notificationRepo->create([
                        'sender_id' => $senderId > 0 ? $senderId : null,
                        'institution_id' => $institutionId,
                        'user_id' => $teacherUserId,
                        'target_role' => 'teacher',
                        'course_id' => $courseId,
                        'title' => $title,
                        'message' => $message,
                        'notification_type' => 'course_material_' . $action,
                        'link' => '/teacher/dashboard.html#course-materials',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('CourseController::notifyCourseMaterialChanged ' . $e->getMessage());
        }
    }

    private function notifyTimetableAudience(int $institutionId, int $courseId, string $action): void
    {
        try {
            if ($institutionId <= 0 || $courseId <= 0) {
                return;
            }

            // Only send schedule notifications when timetable is published.
            if (!$this->institutionRepo->getTimetablePublished($institutionId)) {
                return;
            }

            $title = 'Course Schedule ' . ($action === 'created' ? 'Created' : 'Updated');
            $message = 'A course schedule was ' . $action . ' for class subject (ID: ' . $courseId . ').';

            foreach (['admin', 'teacher', 'student', 'parent'] as $role) {
                $this->notificationRepo->create([
                    'sender_id' => null,
                    'institution_id' => $institutionId,
                    'target_role' => $role,
                    'course_id' => $courseId,
                    'title' => $title,
                    'message' => $message,
                    'notification_type' => 'course_schedule_' . $action,
                    'link' => '/' . $role . '/dashboard.html#timetable',
                ]);
            }
        } catch (\Throwable $e) {
            error_log('CourseController::notifyTimetableAudience ' . $e->getMessage());
        }
    }

    public function index(array $user): void
    {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 20);
        $teacherId = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : null;
        $status = $_GET['status'] ?? null;

        if (AccessControl::isSuperAdmin($user) && isset($_GET['institution_id']) && $_GET['institution_id'] !== '') {
            $institutionId = (int) $_GET['institution_id'];
        } elseif (!AccessControl::isSuperAdmin($user)) {
            $institutionId = AccessControl::institutionId($user);
        } else {
            $institutionId = null;
        }

        $courses = $this->courseRepo->getAll($page, $limit, $teacherId, $status, $institutionId);
        $total = $this->courseRepo->count($teacherId, $status, $institutionId);

        Response::paginated($courses, $total, $page, $limit);
    }

    public function show(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Students can only view courses they're enrolled in
        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $student = $this->studentRepo->findByUserId($user['user_id']);
            if (!$student) {
                Response::forbidden('Student profile not found');
                return;
            }

            if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
                Response::forbidden('You are not enrolled in this course');
                return;
            }
        }

        Response::success($course);
    }

    public function create(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['course_code', 'course_name', 'class_id', 'subject_id']);

        // Validate teacher_id only if provided
        if (isset($data['teacher_id'])) {
            $validator->numeric('teacher_id');
        }

        if (isset($data['class_id'])) {
            $validator->numeric('class_id');
        }

        // Validate new class structure fields
        if (isset($data['program'])) {
            $validator->max('program', 100);
        }

        if (isset($data['grade_level'])) {
            $validator->max('grade_level', 20);
        }

        if (isset($data['section'])) {
            $validator->max('section', 50);
        }

        if (isset($data['subject_id'])) {
            $validator->numeric('subject_id');
        }

        if (isset($data['academic_year_id'])) {
            $validator->numeric('academic_year_id');
        }

        if (isset($data['semester_id'])) {
            $validator->numeric('semester_id');
        }

        $validator->numeric('credits')
            ->numeric('duration_weeks');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        // Determine the institution that will own the course.
        if (AccessControl::isSuperAdmin($user)) {
            $institutionId = (int) ($data['institution_id'] ?? 0);
            if ($institutionId <= 0) {
                Response::error('institution_id is required for super admin', 400);
                return;
            }
        } else {
            $institutionId = AccessControl::institutionId($user);
            if ($institutionId <= 0) {
                Response::error('Missing institution context', 400);
                return;
            }
        }
        $data['institution_id'] = $institutionId;

        // Teachers can only create courses for themselves
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher) {
                Response::forbidden('Teacher profile not found');
                return;
            }
            // Auto-assign teacher_id if not provided
            if (!isset($data['teacher_id'])) {
                $data['teacher_id'] = $teacher['teacher_id'];
            } elseif ($teacher['teacher_id'] != $data['teacher_id']) {
                Response::forbidden('You can only create courses for yourself');
                return;
            }
        }

        $referenceError = $this->validateCourseReferences($data, $institutionId);
        if ($referenceError !== null) {
            Response::validationError(['course' => $referenceError]);
            return;
        }

        $courseId = $this->courseRepo->create($data);

        if (!$courseId) {
            Response::serverError('Failed to create course');
            return;
        }

        $course = $this->courseRepo->findById($courseId);
        Response::success($course, 201);
    }

    public function update(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->hasRole(['admin', 'teacher'])) {
            Response::forbidden('Only admins and teachers can update courses');
            return;
        }

        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Teachers can only update their own courses
        if (AccessControl::hasRole($user, 'teacher') && !AccessControl::isAdmin($user) && !AccessControl::isSuperAdmin($user)) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only update your own courses');
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        if (!AccessControl::isSuperAdmin($user)) {
            unset($data['institution_id']);
            $targetInstitutionId = (int) $course['institution_id'];
        } else {
            $targetInstitutionId = (int) ($data['institution_id'] ?? $course['institution_id'] ?? 0);
            if (isset($data['institution_id']) && (int) $data['institution_id'] <= 0) {
                Response::error('institution_id must be a positive integer', 400);
                return;
            }
        }

        if ($targetInstitutionId <= 0) {
            Response::error('Missing institution context', 400);
            return;
        }

        // If a super admin moves the course to another institution, validate
        // the existing references against the new institution as well.
        $validationData = $data;
        if ($targetInstitutionId !== (int) ($course['institution_id'] ?? 0)) {
            foreach (['class_id', 'subject_id', 'teacher_id', 'academic_year_id', 'semester_id'] as $field) {
                if (!array_key_exists($field, $validationData)) {
                    $validationData[$field] = $course[$field] ?? null;
                }
            }
        }

        $referenceError = $this->validateCourseReferences($validationData, $targetInstitutionId);
        if ($referenceError !== null) {
            Response::validationError(['course' => $referenceError]);
            return;
        }

        if (empty($data)) {
            Response::error('No updatable fields supplied', 400);
            return;
        }

        if ($this->courseRepo->update($id, $data)) {
            $updated = $this->courseRepo->findById($id);
            Response::success($updated);
        } else {
            Response::serverError('Failed to update course');
        }
    }

    public function delete(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!AccessControl::isAdmin($user) && !AccessControl::isSuperAdmin($user) && !AccessControl::hasRole($user, 'teacher')) {
            Response::forbidden('Only admins and teachers can delete courses');
            return;
        }

        $course = $this->courseRepo->findById($id);
        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if ($this->courseRepo->delete($id)) {
            Response::success(['message' => 'Course archived successfully']);
        } else {
            Response::serverError('Failed to archive course');
        }
    }

    public function getEnrolledStudents(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Teachers can only view their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only view students in your own courses');
                return;
            }
        }

        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 20);

        $students = $this->courseRepo->getEnrolledStudents($id, $page, $limit);

        Response::success($students);
    }

    public function getMaterials(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Students can only access materials for courses they're enrolled in
        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $student = $this->studentRepo->findByUserId($user['user_id']);
            if (!$student) {
                Response::forbidden('Student profile not found');
                return;
            }

            if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
                Response::forbidden('You can only access materials for courses you are enrolled in');
                return;
            }
        }

        $materials = $this->materialRepo->getCourseMaterials($id);
        Response::success($materials);
    }

    /**
     * Create a course material
     * POST /api/courses/{id}/materials
     */
    public function createMaterial(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Teachers can only add materials to their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only add materials to your own courses');
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        $validator->required(['title'])
            ->max('title', 200);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $data['course_id'] = $id;

        $materialId = $this->materialRepo->create($data);

        if ($materialId) {
            $this->notifyCourseMaterialChanged($course, 'created', $user);
        }

        Response::success([
            'message' => 'Course material created successfully',
            'material_id' => $materialId
        ], 201);
    }

    /**
     * Update a course material
     * PUT /api/courses/{courseId}/materials/{materialId}
     */
    public function updateMaterial(array $user, int $courseId, int $materialId): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($courseId);
        $material = $this->materialRepo->findById($materialId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (!$material) {
            Response::notFound('Material not found');
            return;
        }

        // Verify material belongs to the course
        if ($material['course_id'] != $courseId) {
            Response::error('Material does not belong to this course', 400);
            return;
        }

        // Teachers can only update materials for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only update materials for your own courses');
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        // No required fields for update

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $this->materialRepo->update($materialId, $data);

        $this->notifyCourseMaterialChanged($course, 'updated', $user);

        Response::success(['message' => 'Course material updated successfully']);
    }

    /**
     * Delete a course material
     * DELETE /api/courses/{courseId}/materials/{materialId}
     */
    public function deleteMaterial(array $user, int $courseId, int $materialId): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($courseId);
        $material = $this->materialRepo->findById($materialId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (!$material) {
            Response::notFound('Material not found');
            return;
        }

        // Verify material belongs to the course
        if ($material['course_id'] != $courseId) {
            Response::error('Material does not belong to this course', 400);
            return;
        }

        // Teachers can only delete materials from their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only delete materials from your own courses');
                return;
            }
        }

        $this->materialRepo->delete($materialId);

        Response::success(['message' => 'Course material deleted successfully']);
    }

    public function getAssessments(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Students can only view assessments for enrolled courses
        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $student = $this->studentRepo->findByUserId($user['user_id']);
            if (!$student) {
                Response::forbidden('Student profile not found');
                return;
            }

            if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
                Response::forbidden('You can only view assessments for enrolled courses');
                return;
            }
        }

        $assessments = $this->courseRepo->getAssessments($id);
        Response::success($assessments);
    }

    // ==========================================
    // COURSE REVIEWS
    // ==========================================

    /**
     * Get reviews for a course
     * GET /api/courses/{id}/reviews
     */
    public function getReviews(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Students can only view reviews for enrolled courses
        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $student = $this->studentRepo->findByUserId($user['user_id']);
            if (!$student) {
                Response::forbidden('Student profile not found');
                return;
            }

            if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
                Response::forbidden('You can only view reviews for enrolled courses');
                return;
            }
        }

        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 20);

        $reviews = $this->reviewRepo->getCourseReviews($id, $page, $limit);
        $total = $this->reviewRepo->countCourseReviews($id);
        $stats = $this->reviewRepo->getCourseReviewStats($id);

        Response::success([
            'reviews' => $reviews,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Create a review for a course
     * POST /api/courses/{id}/reviews
     */
    public function createReview(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        // Get student record
        $student = $this->studentRepo->findByUserId($user['user_id']);
        if (!$student) {
            Response::error('Student profile not found', 404);
            return;
        }

        // Students can only review courses they're enrolled in
        if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
            Response::forbidden('You can only review courses you are enrolled in');
            return;
        }

        // Check if student already reviewed this course
        $existingReview = $this->reviewRepo->findByStudentAndCourse($student['student_id'], $id);
        if ($existingReview) {
            Response::error('You have already reviewed this course. Use update instead.', 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        $validator->required(['rating']);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $data['course_id'] = $id;
        $data['student_id'] = $student['student_id'];

        $reviewId = $this->reviewRepo->create($data);

        Response::success([
            'message' => 'Review created successfully',
            'review_id' => $reviewId
        ], 201);
    }

    /**
     * Update a review
     * PUT /api/courses/{courseId}/reviews/{reviewId}
     */
    public function updateReview(array $user, int $courseId, int $reviewId): void
    {
        $course = $this->courseRepo->findById($courseId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        $review = $this->reviewRepo->findById($reviewId);

        if (!$review) {
            Response::notFound('Review not found');
            return;
        }

        if ((int) ($review['course_id'] ?? 0) !== $courseId) {
            Response::error('Review does not belong to this course', 400);
            return;
        }

        // Check if review belongs to the student
        $student = $this->studentRepo->findByUserId($user['user_id']);
        if (!$student || $review['student_id'] !== $student['student_id']) {
            Response::error('Unauthorized. You can only update your own reviews.', 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        // No required fields for update

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $this->reviewRepo->update($reviewId, $data);

        Response::success(['message' => 'Review updated successfully']);
    }

    /**
     * Delete a review
     * DELETE /api/courses/{courseId}/reviews/{reviewId}
     */
    public function deleteReview(array $user, int $courseId, int $reviewId): void
    {
        $course = $this->courseRepo->findById($courseId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        $review = $this->reviewRepo->findById($reviewId);

        if (!$review) {
            Response::notFound('Review not found');
            return;
        }

        if ((int) ($review['course_id'] ?? 0) !== $courseId) {
            Response::error('Review does not belong to this course', 400);
            return;
        }

        // Students can delete their own reviews, admins can delete any
        $roleMiddleware = new RoleMiddleware($user);
        $student = $this->studentRepo->findByUserId($user['user_id']);

        if (!$roleMiddleware->isAdmin() && (!$student || $review['student_id'] !== $student['student_id'])) {
            Response::error('Unauthorized', 403);
            return;
        }

        $this->reviewRepo->delete($reviewId);

        Response::success(['message' => 'Review deleted successfully']);
    }

    // ==========================================
    // COURSE SCHEDULES
    // ==========================================

    /**
     * Get schedules for a course
     * GET /api/courses/{id}/schedules
     */
    public function getSchedules(array $user, int $id): void
    {
        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (
            $user['role'] !== 'super_admin'
            && isset($user['institution_id'], $course['institution_id'])
            && (int) $course['institution_id'] !== (int) $user['institution_id']
        ) {
            Response::forbidden('You can only access schedules in your institution');
            return;
        }

        // Students can only view schedules for enrolled courses
        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $student = $this->studentRepo->findByUserId($user['user_id']);
            if (!$student) {
                Response::forbidden('Student profile not found');
                return;
            }

            if (!$this->courseRepo->isStudentEnrolled($student['student_id'], $id)) {
                Response::forbidden('You can only view schedules for enrolled courses');
                return;
            }
        }

        $schedules = $this->scheduleRepo->getCourseSchedules($id);

        Response::success($schedules);
    }

    /**
     * Create a schedule for a course
     * POST /api/courses/{id}/schedules
     */
    public function createSchedule(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($id);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (
            $user['role'] !== 'super_admin'
            && isset($user['institution_id'], $course['institution_id'])
            && (int) $course['institution_id'] !== (int) $user['institution_id']
        ) {
            Response::forbidden('You can only create schedules in your institution');
            return;
        }

        // Teachers can only create schedules for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only create schedules for your own courses');
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        $validator->required(['day_of_week', 'start_time', 'end_time']);

        if (isset($data['period_label'])) {
            $validator->maxLength('period_label', 50);
        }

        if (isset($data['status'])) {
            $validator->in('status', ['active', 'inactive']);
        }

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $data['course_id'] = $id;
        if (isset($course['institution_id'])) {
            $data['institution_id'] = $course['institution_id'];
        } elseif (isset($user['institution_id'])) {
            $data['institution_id'] = $user['institution_id'];
        }

        $scheduleId = $this->scheduleRepo->create($data);

        if ($scheduleId) {
            $this->notifyTimetableAudience((int) ($data['institution_id'] ?? 0), $id, 'created');
        }

        Response::success([
            'message' => 'Schedule created successfully',
            'schedule_id' => $scheduleId
        ], 201);
    }

    /**
     * Update a schedule
     * PUT /api/courses/{courseId}/schedules/{scheduleId}
     */
    public function updateSchedule(array $user, int $courseId, int $scheduleId): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($courseId);
        $schedule = $this->scheduleRepo->findById($scheduleId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (!$schedule) {
            Response::notFound('Schedule not found');
            return;
        }

        if ((int) $schedule['course_id'] !== (int) $courseId) {
            Response::error('Schedule does not belong to this course', 400);
            return;
        }

        // Teachers can only update schedules for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only update schedules for your own courses');
                return;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validator = new Validator($data);
        // No required fields for update

        if (isset($data['period_label'])) {
            $validator->maxLength('period_label', 50);
        }

        if (isset($data['status'])) {
            $validator->in('status', ['active', 'inactive']);
        }

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $this->scheduleRepo->update($scheduleId, $data);

        $this->notifyTimetableAudience((int) ($course['institution_id'] ?? 0), $courseId, 'updated');

        Response::success(['message' => 'Schedule updated successfully']);
    }

    /**
     * Delete a schedule
     * DELETE /api/courses/{courseId}/schedules/{scheduleId}
     */
    public function deleteSchedule(array $user, int $courseId, int $scheduleId): void
    {
        $roleMiddleware = new RoleMiddleware($user);

        if (!$roleMiddleware->requireRole(['admin', 'teacher'])) {
            return;
        }

        $course = $this->courseRepo->findById($courseId);
        $schedule = $this->scheduleRepo->findById($scheduleId);

        if (!$course) {
            Response::notFound('Course not found');
            return;
        }


        if (!AccessControl::isSuperAdmin($user)
            && (int) $course['institution_id'] !== AccessControl::institutionId($user)) {
            Response::notFound('Course not found');
            return;
        }

        if (!$schedule) {
            Response::notFound('Schedule not found');
            return;
        }

        if ((int) $schedule['course_id'] !== (int) $courseId) {
            Response::error('Schedule does not belong to this course', 400);
            return;
        }

        // Teachers can only delete schedules for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()) {
            $teacher = $this->teacherRepo->findByUserId($user['user_id']);
            if (!$teacher || $course['teacher_id'] != $teacher['teacher_id']) {
                Response::forbidden('You can only delete schedules for your own courses');
                return;
            }
        }

        $this->scheduleRepo->delete($scheduleId);

        Response::success(['message' => 'Schedule deleted successfully']);
    }
}
