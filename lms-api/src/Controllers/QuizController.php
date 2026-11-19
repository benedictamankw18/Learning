<?php

namespace App\Controllers;

use App\Repositories\QuizRepository;
use App\Repositories\QuestionBankRepository;
use App\Repositories\ClassSubjectRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TeacherRepository;
use App\Repositories\StudentRepository;
use App\Utils\Response;
use App\Utils\Validator;
use App\Utils\QuestionCsv;
use App\Middleware\RoleMiddleware;
use App\Security\AccessControl;
use DateTime;
use Exception;

class QuizController
{
    private function normalizeDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTime($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
    private QuizRepository $repo;
    private ClassSubjectRepository $courseRepo;
    private NotificationRepository $notificationRepo;
    private TeacherRepository $teacherRepo;
    private StudentRepository $studentRepo;

    public function __construct()
    {
        $this->repo = new QuizRepository();
        $this->courseRepo = new ClassSubjectRepository();
        $this->notificationRepo = new NotificationRepository();
        $this->teacherRepo = new TeacherRepository();
        $this->studentRepo = new StudentRepository();
    }

    /**
     * Determine whether a quiz is active by either flag.
     */
    private function isQuizActive(array $quiz): bool
    {
        return strtolower((string) ($quiz['status'] ?? '')) === 'active'
            || !empty($quiz['is_activated']);
    }

    /**
     * Send a one-time activation notification when a quiz becomes active.
     */
    private function notifyQuizActivated(array $quiz, array $actor): void
    {
        try {
            $quizId = (int) ($quiz['quiz_id'] ?? 0);
            $courseId = (int) ($quiz['course_id'] ?? 0);
            if ($quizId <= 0 || $courseId <= 0) {
                return;
            }

            $course = $this->courseRepo->findById($courseId);
            if (!$course) {
                return;
            }

            $courseName = $this->courseRepo->getNameById($courseId) ?: 'the assigned class subject';
            $quizTitle = trim((string) ($quiz['title'] ?? 'Untitled Quiz'));
            $title = 'Quiz Activated';
            $message = "Quiz '{$quizTitle}' is now active for {$courseName} (ID: {$courseId}).";

            $actorUserId = (int) ($actor['user_id'] ?? 0);
            $institutionId = (int) ($course['institution_id'] ?? 0);

            $students = $this->courseRepo->getEnrolledStudents($courseId);
            foreach ($students as $student) {
                $studentUserId = (int) ($student['user_id'] ?? 0);
                if ($studentUserId <= 0) {
                    continue;
                }

                if ($this->notificationRepo->quizActivationExists($studentUserId, $courseId, $title, $message)) {
                    continue;
                }

                $this->notificationRepo->create([
                    'sender_id' => $actorUserId,
                    'institution_id' => $institutionId > 0 ? $institutionId : 1,
                    'user_id' => $studentUserId,
                    'target_role' => 'student',
                    'course_id' => $courseId,
                    'title' => $title,
                    'message' => $message,
                    'notification_type' => 'quiz_activated',
                    'link' => '/student/dashboard.html#quizzes',
                ]);
            }

            $teacherId = (int) ($course['teacher_id'] ?? 0);
            if ($teacherId > 0) {
                $teacher = $this->teacherRepo->findById($teacherId);
                $teacherUserId = (int) ($teacher['user_id'] ?? 0);
                if ($teacherUserId > 0 && !$this->notificationRepo->quizActivationExists($teacherUserId, $courseId, $title, $message)) {
                    $this->notificationRepo->create([
                        'sender_id' => $actorUserId,
                        'institution_id' => $institutionId > 0 ? $institutionId : 1,
                        'user_id' => $teacherUserId,
                        'target_role' => 'teacher',
                        'course_id' => $courseId,
                        'title' => $title,
                        'message' => $message,
                        'notification_type' => 'quiz_activated',
                        'link' => '/teacher/dashboard.html#quizzes',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('QuizController::notifyQuizActivated error: ' . $e->getMessage());
        }
    }

    /**
     * Resolve student_id from auth payload; fallback to lookup by user_id.
     */
    private function resolveStudentId(array $user): ?int
    {
        if (isset($user['student_id']) && is_numeric($user['student_id'])) {
            $studentId = (int) $user['student_id'];
            return $studentId > 0 ? $studentId : null;
        }

        if (!isset($user['user_id']) || !is_numeric($user['user_id'])) {
            return null;
        }

        $student = $this->studentRepo->findByUserId((int) $user['user_id']);
        if (!$student || !isset($student['student_id']) || !is_numeric($student['student_id'])) {
            return null;
        }

        $studentId = (int) $student['student_id'];

            /**
             * Notify students when quiz becomes inactive
             */
            
        return $studentId > 0 ? $studentId : null;
    }


    private function notifyQuizDeactivated(array $quiz, array $actor): void
            {
                try {
                    $quizId = (int) ($quiz['quiz_id'] ?? 0);
                    $courseId = (int) ($quiz['course_id'] ?? 0);
                    if ($quizId <= 0 || $courseId <= 0) {
                        return;
                    }

                    $course = $this->courseRepo->findById($courseId);
                    if (!$course) {
                        return;
                    }

                    $courseName = $this->courseRepo->getNameById($courseId) ?: 'the assigned class subject';
                    $quizTitle = trim((string) ($quiz['title'] ?? 'Untitled Quiz'));
                    $title = 'Quiz Deactivated';
                    $message = "Quiz '{$quizTitle}' has been deactivated for {$courseName}.";

                    $actorUserId = (int) ($actor['user_id'] ?? 0);
                    $institutionId = (int) ($course['institution_id'] ?? 0);

                    $students = $this->courseRepo->getEnrolledStudents($courseId);
                    foreach ($students as $student) {
                        $studentUserId = (int) ($student['user_id'] ?? 0);
                        if ($studentUserId <= 0) {
                            continue;
                        }

                        $this->notificationRepo->create([
                            'sender_id' => $actorUserId,
                            'institution_id' => $institutionId > 0 ? $institutionId : 1,
                            'user_id' => $studentUserId,
                            'target_role' => 'student',
                            'course_id' => $courseId,
                            'title' => $title,
                            'message' => $message,
                            'notification_type' => 'quiz_deactivated',
                            'link' => '/student/dashboard.html#quizzes',
                        ]);
                    }
                } catch (\Throwable $e) {
                    error_log('QuizController::notifyQuizDeactivated error: ' . $e->getMessage());
                }
            }

            /**
             * Notify students about upcoming or overdue quiz deadlines
             */
            private function notifyQuizDueDates(array $oldQuiz, array $newData, array $actor): void
            {
                try {
                    $quizId = (int) ($oldQuiz['quiz_id'] ?? 0);
                    $courseId = (int) ($oldQuiz['course_id'] ?? 0);
                    if ($quizId <= 0 || $courseId <= 0) {
                        return;
                    }

                    $course = $this->courseRepo->findById($courseId);
                    if (!$course) {
                        return;
                    }

                    $quizTitle = trim((string) ($oldQuiz['title'] ?? 'Untitled Quiz'));
                    $courseName = $this->courseRepo->getNameById($courseId) ?: 'the assigned class subject';
                    $actorUserId = (int) ($actor['user_id'] ?? 0);
                    $institutionId = (int) ($course['institution_id'] ?? 0);

                    // Check if start_date was set or changed
                    $oldStartDate = $oldQuiz['start_date'] ?? null;
                    $newStartDate = $newData['start_date'] ?? $oldStartDate;
            
                    if ($newStartDate && $newStartDate !== $oldStartDate) {
                        $title = 'Quiz Start Date Set';
                        $message = "Quiz '{$quizTitle}' will start on " . date('M d, Y H:i', strtotime($newStartDate)) . " for {$courseName}.";

                        $students = $this->courseRepo->getEnrolledStudents($courseId);
                        foreach ($students as $student) {
                            $studentUserId = (int) ($student['user_id'] ?? 0);
                            if ($studentUserId <= 0) {
                                continue;
                            }

                            $this->notificationRepo->create([
                                'sender_id' => $actorUserId,
                                'institution_id' => $institutionId > 0 ? $institutionId : 1,
                                'user_id' => $studentUserId,
                                'target_role' => 'student',
                                'course_id' => $courseId,
                                'title' => $title,
                                'message' => $message,
                                'notification_type' => 'quiz_start_date_set',
                                'link' => '/student/dashboard.html#quizzes',
                            ]);
                        }
                    }

                    // Check if end_date was set or changed
                    $oldEndDate = $oldQuiz['end_date'] ?? null;
                    $newEndDate = $newData['end_date'] ?? $oldEndDate;
            
                    if ($newEndDate && $newEndDate !== $oldEndDate) {
                        $title = 'Quiz Deadline Set';
                        $message = "Quiz '{$quizTitle}' must be completed by " . date('M d, Y H:i', strtotime($newEndDate)) . " for {$courseName}.";

                        $students = $this->courseRepo->getEnrolledStudents($courseId);
                        foreach ($students as $student) {
                            $studentUserId = (int) ($student['user_id'] ?? 0);
                            if ($studentUserId <= 0) {
                                continue;
                            }

                            $this->notificationRepo->create([
                                'sender_id' => $actorUserId,
                                'institution_id' => $institutionId > 0 ? $institutionId : 1,
                                'user_id' => $studentUserId,
                                'target_role' => 'student',
                                'course_id' => $courseId,
                                'title' => $title,
                                'message' => $message,
                                'notification_type' => 'quiz_deadline_set',
                                'link' => '/student/dashboard.html#quizzes',
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('QuizController::notifyQuizDueDates error: ' . $e->getMessage());
                }
            }

            /**
             * Notify student and teacher when quiz submission status becomes 'submitted'
             */
            private function notifyQuizSubmitted(int $quizId, int $studentId): void
            {
                try {
                    $quiz = $this->repo->findById($quizId);
                    if (!$quiz) {
                        return;
                    }

                    $courseId = (int) ($quiz['course_id'] ?? 0);
                    $course = $this->courseRepo->findById($courseId);
                    if (!$course) {
                        return;
                    }

                    $student = $this->studentRepo->findById($studentId);
                    if (!$student) {
                        return;
                    }

                    $studentUser = $this->studentRepo->findByUserId((int) ($student['user_id'] ?? 0)) ? $student['user_id'] : null;
                    if (!$studentUser) {
                        return;
                    }

                    $quizTitle = trim((string) ($quiz['title'] ?? 'Untitled Quiz'));
                    $courseName = $this->courseRepo->getNameById($courseId) ?: 'the assigned class subject';
                    $studentName = trim((string) (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
                    $institutionId = (int) ($course['institution_id'] ?? 0);

                    // Notify student
                    $this->notificationRepo->create([
                        'sender_id' => null,
                        'institution_id' => $institutionId > 0 ? $institutionId : 1,
                        'user_id' => (int) $studentUser,
                        'target_role' => 'student',
                        'course_id' => $courseId,
                        'title' => 'Quiz Submitted',
                        'message' => "You have successfully submitted quiz '{$quizTitle}' for {$courseName}.",
                        'notification_type' => 'quiz_submitted',
                        'link' => '/student/dashboard.html#quizzes',
                    ]);

                    // Notify teacher
                    $teacherId = (int) ($course['teacher_id'] ?? 0);
                    if ($teacherId > 0) {
                        $teacher = $this->teacherRepo->findById($teacherId);
                        $teacherUserId = (int) ($teacher['user_id'] ?? 0);
                        if ($teacherUserId > 0) {
                            $this->notificationRepo->create([
                                'sender_id' => (int) $studentUser,
                                'institution_id' => $institutionId > 0 ? $institutionId : 1,
                                'user_id' => $teacherUserId,
                                'target_role' => 'teacher',
                                'course_id' => $courseId,
                                'title' => 'Quiz Submitted',
                                'message' => "{$studentName} has submitted quiz '{$quizTitle}' for {$courseName}.",
                                'notification_type' => 'quiz_submitted_teacher',
                                'link' => '/teacher/dashboard.html#quizzes',
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('QuizController::notifyQuizSubmitted error: ' . $e->getMessage());
                }
            }

            /**
             * Notify student when quiz submission starts (in_progress status)
             */
            private function notifyQuizInProgress(int $quizId, int $studentId): void
            {
                try {
                    $quiz = $this->repo->findById($quizId);
                    if (!$quiz) {
                        return;
                    }

                    $courseId = (int) ($quiz['course_id'] ?? 0);
                    $course = $this->courseRepo->findById($courseId);
                    if (!$course) {
                        return;
                    }

                    $student = $this->studentRepo->findById($studentId);
                    if (!$student) {
                        return;
                    }

                    $studentUserId = (int) ($student['user_id'] ?? 0);
                    if ($studentUserId <= 0) {
                        return;
                    }

                    $quizTitle = trim((string) ($quiz['title'] ?? 'Untitled Quiz'));
                    $courseName = $this->courseRepo->getNameById($courseId) ?: 'the assigned class subject';
                    $institutionId = (int) ($course['institution_id'] ?? 0);
                    $durationMinutes = (int) ($quiz['duration_minutes'] ?? 0);
                    $durationText = $durationMinutes > 0 ? "You have {$durationMinutes} minutes to complete it." : '';

                    $this->notificationRepo->create([
                        'sender_id' => null,
                        'institution_id' => $institutionId > 0 ? $institutionId : 1,
                        'user_id' => $studentUserId,
                        'target_role' => 'student',
                        'course_id' => $courseId,
                        'title' => 'Quiz In Progress',
                        'message' => "You have started quiz '{$quizTitle}' for {$courseName}. {$durationText}",
                        'notification_type' => 'quiz_in_progress',
                        'link' => '/student/dashboard.html#quizzes',
                    ]);
                } catch (\Throwable $e) {
                    error_log('QuizController::notifyQuizInProgress error: ' . $e->getMessage());
                }
            }
            
    /**
     * Get all quizzes for a course
     * GET /courses/{courseId}/quizzes
     */
    public function getByCourse(array $user, int $courseId): void
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

        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isAdmin()) {
            // Admins and super admins may view any course in their institution.
        } elseif ($roleMiddleware->isTeacher()) {
            if ((int) ($course['teacher_id'] ?? 0) !== (int) ($user['teacher_id'] ?? 0)) {
                Response::error('Course not found', 404);
                return;
            }
        } elseif ($roleMiddleware->isStudent()) {
            $studentId = $this->resolveStudentId($user);
            if ($studentId === null
                || !$this->courseRepo->isStudentEnrolledInCourse($courseId, $studentId)) {
                Response::error('Course not found', 404);
                return;
            }
        } else {
            Response::forbidden('You do not have access to this course');
            return;
        }

        $quizzes = $this->repo->getCourseQuizzes($courseId);

        // Student visibility rule: only active quizzes should be visible.
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $quizzes = array_values(array_filter($quizzes, static function ($quiz) {
                return strtolower((string) ($quiz['status'] ?? '')) === 'active';
            }));
        }

        Response::success([
            'quizzes' => $quizzes,
            'count' => count($quizzes)
        ]);
    }

    /**
     * Get a single quiz
     * GET /quizzes/{id}
     */
    public function show(array $user, int $id): void
    {
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isAdmin()) {
            // Admins and super admins may view any quiz in their institution.
        } elseif ($roleMiddleware->isTeacher()) {
            if ((int) ($quiz['teacher_id'] ?? 0) !== (int) ($user['teacher_id'] ?? 0)) {
                Response::error('Quiz not found', 404);
                return;
            }
        } elseif ($roleMiddleware->isStudent()) {
            $studentId = $this->resolveStudentId($user);
            if ($studentId === null
                || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)
                || !$this->isQuizActive($quiz)) {
                Response::error('Quiz not found', 404);
                return;
            }
        } else {
            Response::forbidden('You do not have access to this quiz');
            return;
        }

        Response::success($quiz);
    }

    /**
     * Create a new quiz (teacher/admin)
     * POST /quizzes
     */
    public function create(array $user): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['course_id', 'title', 'duration_minutes'])
            ->numeric('course_id')
            ->numeric('section_id')
            ->max('title', 200)
            ->numeric('duration_minutes')
            ->numeric('max_attempts')
            ->numeric('randomize_questions')
            ->numeric('prevent_backtracking')
            ->in('status', ['draft', 'active', 'archived'])
            ->in('quiz_type', ['graded', 'practice', 'survey'])
            ->in('show_results', ['instant', 'after_end', 'never']);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('prevent_backtracking', $data)) {
            $data['prevent_backtracking'] = !empty($data['prevent_backtracking']) ? 1 : 0;
        }

        if (array_key_exists('start_date', $data)) {
            $data['start_date'] = $this->normalizeDateTime($data['start_date']);
        }
        if (array_key_exists('end_date', $data)) {
            $data['end_date'] = $this->normalizeDateTime($data['end_date']);
        }

        // Check if course exists
        $course = $this->courseRepo->findById($data['course_id']);

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

        // Teachers can only create quizzes for their own courses
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($course['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only create quizzes for your own courses');
            return;
        }

        $quizId = $this->repo->create($data);

        $createdQuiz = $this->repo->findById($quizId);
        if ($createdQuiz && $this->isQuizActive(array_merge($data, $createdQuiz))) {
            $this->notifyQuizActivated($createdQuiz, $user);
        }

        Response::success([
            'message' => 'Quiz created successfully',
            'quiz_id' => $quizId
        ], 201);
    }

    /**
     * Update a quiz (teacher/admin)
     * PUT /quizzes/{id}
     */
    public function update(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Teachers can only update their own quizzes
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only update your own quizzes');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->numeric('section_id')
            ->max('title', 200)
            ->numeric('duration_minutes')
            ->numeric('max_attempts')
            ->numeric('randomize_questions')
            ->numeric('prevent_backtracking')
            ->in('status', ['draft', 'active', 'archived'])
            ->in('quiz_type', ['graded', 'practice', 'survey'])
            ->in('show_results', ['instant', 'after_end', 'never']);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('prevent_backtracking', $data)) {
            $data['prevent_backtracking'] = !empty($data['prevent_backtracking']) ? 1 : 0;
        }

        if (array_key_exists('start_date', $data)) {
            $data['start_date'] = $this->normalizeDateTime($data['start_date']);
        }
        if (array_key_exists('end_date', $data)) {
            $data['end_date'] = $this->normalizeDateTime($data['end_date']);
        }

        $wasActive = $this->isQuizActive($quiz);
        $willBeActive = $this->isQuizActive(array_merge($quiz, $data));

        $this->repo->update($id, $data);

        if (!$wasActive && $willBeActive) {
            $updatedQuiz = $this->repo->findById($id);
            if ($updatedQuiz) {
                $this->notifyQuizActivated($updatedQuiz, $user);

                    // Notify when quiz becomes inactive
                    if ($wasActive && !$willBeActive) {
                        $updatedQuiz = $this->repo->findById($id);
                        if ($updatedQuiz) {
                            $this->notifyQuizDeactivated($updatedQuiz, $user);
                        }
                    }

                    // Notify for due date changes (start_date and end_date)
                    $this->notifyQuizDueDates($quiz, $data, $user);
            }
        }

        Response::success(['message' => 'Quiz updated successfully']);
    }

    /**
     * Delete a quiz (teacher/admin)
     * DELETE /quizzes/{id}
     */
    public function delete(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Teachers can only delete their own quizzes
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only delete your own quizzes');
            return;
        }

        $this->repo->delete($id);

        Response::success(['message' => 'Quiz deleted successfully']);
    }

    /**
     * Remove answer keys before questions are sent to a student.
     */
    private function stripAnswerKeys(array $questions): array
    {
        foreach ($questions as &$question) {
            unset($question['correct_answer'], $question['explanation']);
            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as &$option) {
                    unset($option['is_correct']);
                }
                unset($option);
            }
        }
        unset($question);

        return $questions;
    }

    /**
     * Get questions for a quiz (teacher/admin or enrolled student)
     * GET /quizzes/{id}/questions
     */
    public function getQuestions(array $user, int $id): void
    {
        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $roleMiddleware = new RoleMiddleware($user);
        if ($roleMiddleware->isAdmin()) {
            // Admins and super admins may view any quiz's questions in their institution.
        } elseif ($roleMiddleware->isTeacher()) {
            if ((int) ($quiz['teacher_id'] ?? 0) !== (int) ($user['teacher_id'] ?? 0)) {
                Response::error('Quiz not found', 404);
                return;
            }
        } elseif ($roleMiddleware->isStudent()) {
            $studentId = $this->resolveStudentId($user);
            if ($studentId === null
                || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)
                || !$this->isQuizActive($quiz)) {
                Response::error('Quiz not found', 404);
                return;
            }
        } else {
            Response::forbidden('You do not have access to this quiz');
            return;
        }

        $questions = $this->repo->getQuestions($id);

        // Never expose answer keys to students while they are taking the quiz.
        if ($roleMiddleware->isStudent() && !$roleMiddleware->isAdmin()) {
            $questions = $this->stripAnswerKeys($questions);
        }

        Response::success([
            'questions' => $questions,
            'count' => count($questions)
        ]);
    }

    /**
     * Get the questions for one of the student's own attempts, in the order
     * that was generated when the attempt started.
     * GET /quiz-submissions/{id}/questions
     */
    public function getSubmissionQuestions(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        $submission = $this->repo->getSubmissionById($id);
        if (!$submission) {
            Response::error('Submission not found', 404);
            return;
        }

        // Only the student who owns the attempt may read its questions.
        if ((int) $submission['student_id'] !== $studentId
            || (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Submission not found', 404);
            return;
        }

        if (strtolower((string) ($submission['status'] ?? '')) !== 'in_progress') {
            Response::error('Quiz already submitted', 400);
            return;
        }

        $quiz = $this->repo->findById((int) $submission['quiz_id']);
        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!$this->isQuizActive($quiz)
            || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $questions = $this->repo->getSubmissionQuestions(
            $id,
            (int) $quiz['quiz_id'],
            !empty($quiz['randomize_questions'])
        );

        Response::success([
            'submission_id' => $id,
            'questions' => $this->stripAnswerKeys($questions),
            'count' => count($questions),
            'prevent_backtracking' => !empty($quiz['prevent_backtracking']) ? 1 : 0
        ]);
    }

    /**
     * Current navigation policy for an in-progress attempt.
     *
     * The student UI re-reads this before every move so a quiz switched to
     * "Prevent Going Back" mid-attempt takes effect in already-open tabs.
     * GET /quiz-submissions/{id}/policy
     */
    public function getSubmissionPolicy(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        $submission = $this->repo->getSubmissionById($id);
        if (!$submission) {
            Response::error('Submission not found', 404);
            return;
        }

        if ((int) $submission['student_id'] !== $studentId
            || (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Submission not found', 404);
            return;
        }

        if (strtolower((string) ($submission['status'] ?? '')) !== 'in_progress') {
            Response::error('Quiz already submitted', 400);
            return;
        }

        $quiz = $this->repo->findById((int) $submission['quiz_id']);
        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!$this->isQuizActive($quiz)
            || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $questionIds = $this->repo->getSubmissionQuestionOrder($id);
        if (empty($questionIds)) {
            $questionIds = $this->repo->ensureSubmissionQuestionOrder(
                $id,
                (int) $quiz['quiz_id'],
                !empty($quiz['randomize_questions'])
            );
        }

        Response::success([
            'submission_id' => $id,
            'prevent_backtracking' => !empty($quiz['prevent_backtracking']) ? 1 : 0,
            'furthest_position' => (int) ($submission['furthest_position'] ?? 0),
            'question_count' => count($questionIds),
        ]);
    }

    /**
     * Record the question a student is leaving, and the answer they gave it.
     * POST /quiz-submissions/{id}/progress
     */
    public function recordProgress(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        $submission = $this->repo->getSubmissionById($id);
        if (!$submission) {
            Response::error('Submission not found', 404);
            return;
        }

        if ((int) $submission['student_id'] !== $studentId
            || (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Submission not found', 404);
            return;
        }

        if (strtolower((string) ($submission['status'] ?? '')) !== 'in_progress') {
            Response::error('Quiz already submitted', 400);
            return;
        }

        $quiz = $this->repo->findById((int) $submission['quiz_id']);
        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!$this->isQuizActive($quiz)
            || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['position', 'next_position'])
            ->numeric('position')
            ->numeric('next_position');
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $questionIds = $this->repo->getSubmissionQuestionOrder($id);
        if (empty($questionIds)) {
            $questionIds = $this->repo->ensureSubmissionQuestionOrder(
                $id,
                (int) $quiz['quiz_id'],
                !empty($quiz['randomize_questions'])
            );
        }

        $position = (int) $data['position'];
        $nextPosition = (int) $data['next_position'];
        $lastPosition = count($questionIds) - 1;
        if ($position < 0 || $position > $lastPosition || $nextPosition < 0 || $nextPosition > $lastPosition) {
            Response::error('Invalid question position', 400);
            return;
        }

        $answer = $data['answer'] ?? '';
        if (is_array($answer) || is_object($answer)) {
            Response::error('Invalid answer', 400);
            return;
        }

        $preventBacktracking = !empty($quiz['prevent_backtracking']);
        $result = $this->repo->recordSubmissionProgress(
            $id,
            $position,
            $nextPosition,
            trim((string) $answer),
            $preventBacktracking
        );

        if ($result['error'] !== null) {
            Response::error($result['error'], 400);
            return;
        }

        Response::success([
            'submission_id' => $id,
            'prevent_backtracking' => $preventBacktracking ? 1 : 0,
            'furthest_position' => $result['furthest_position'],
        ]);
    }

    /**
     * Add question to quiz (teacher/admin)
     * POST /quizzes/{id}/questions
     */
    public function addQuestion(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Teachers can only add questions to their own quizzes
        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only add questions to your own quizzes');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['question_text', 'question_type'])
            ->in('question_type', ['multiple_choice', 'true_false', 'short_answer', 'essay'])
            ->numeric('points')
            ->in('difficulty', ['easy', 'medium', 'hard'])
            ->max('image_question', 255)
            ->max('image_name', 255)
            ->max('correct_answer', 500)
            ->numeric('order_index');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('image_name', $data) && !array_key_exists('image_question', $data)) {
            $data['image_question'] = $data['image_name'];
        }

        if (array_key_exists('image_question', $data)) {
            $data['image_question'] = $this->normalizeImageQuestionPath($data['image_question']);
        }

        if (!empty($data['options']) && is_array($data['options'])) {
            $normalizedOptions = [];
            foreach ($data['options'] as $option) {
                $label = strtoupper(trim((string) ($option['label'] ?? '')));
                $text = trim((string) ($option['text'] ?? ''));
                if (!$label || !$text) {
                    continue;
                }

                $normalizedOptions[] = [
                    'label' => $label,
                    'text' => $text,
                    'is_correct' => !empty($option['is_correct']) ? 1 : 0,
                ];
            }

            $data['options'] = $normalizedOptions;
        }

        $data['quiz_id'] = $id;
        $data['actor_user_id'] = (int) ($user['user_id'] ?? 0);

        $questionId = $this->repo->addQuestion($data);

        Response::success([
            'message' => 'Question added successfully',
            'question_id' => $questionId
        ], 201);
    }

    /**
     * Link an existing bank question to a quiz (teacher/admin)
     * POST /quizzes/{id}/questions/link
     */
    public function linkExistingQuestion(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        $quiz = $this->repo->findById($id);
        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only add questions to your own quizzes');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['question_id']) || !is_numeric($data['question_id'])) {
            Response::validationError(['question_id' => ['question_id is required']]);
            return;
        }

        $questionId = (int) $data['question_id'];
        $question = (new QuestionBankRepository())->findVisibleQuestion($questionId, $user);
        if (!$question) {
            Response::error('Question not found', 404);
            return;
        }

        if (!$this->repo->linkExistingQuestion($id, $questionId)) {
            Response::validationError([
                'question_id' => ['This question is unavailable or is already in the quiz'],
            ]);
            return;
        }

        Response::success([
            'message' => 'Question added from the bank',
            'question_id' => $questionId,
        ], 201);
    }

    /**
     * Import questions from a CSV file (teacher/admin)
     * POST /quizzes/{id}/questions/import
     */
    public function importQuestions(array $user, int $id): void
    {
        $quiz = $this->findManageableQuiz($user, $id, 'You can only import questions into your own quizzes');
        if (!$quiz) {
            return;
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('No CSV file uploaded', 400);
            return;
        }

        $file = $_FILES['file'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            Response::error($this->describeUploadError($uploadError), 400);
            return;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            Response::error('The uploaded file could not be read', 400);
            return;
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            Response::error('Please upload a .csv file', 422);
            return;
        }

        if ($size <= 0) {
            Response::error('The uploaded CSV file is empty', 422);
            return;
        }

        if ($size > QuestionCsv::MAX_FILE_BYTES) {
            Response::error('The CSV file is too large (maximum 2 MB)', 422);
            return;
        }

        $contents = file_get_contents($tmpName);
        if (!is_string($contents) || trim($contents) === '') {
            Response::error('The uploaded CSV file is empty', 422);
            return;
        }

        $parsed = QuestionCsv::parse($contents);
        if (!empty($parsed['error'])) {
            Response::error((string) $parsed['error'], 422);
            return;
        }

        if (empty($parsed['rows'])) {
            Response::error('The CSV file has a header but no question rows', 422);
            return;
        }

        $prepared = [];
        $results = [];
        $invalid = 0;

        foreach ($parsed['rows'] as $row) {
            $rowNumber = (int) $row['row'];

            try {
                $question = QuestionCsv::buildQuestion($row['data']);

                if ($question['image_question'] !== null) {
                    $question['image_question'] = $this->normalizeImageQuestionPath($question['image_question']);
                }

                $question['actor_user_id'] = (int) ($user['user_id'] ?? 0);
                $prepared[] = ['row' => $rowNumber, 'question' => $question];
            } catch (Exception $e) {
                $invalid++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $imported = 0;
        $skipped = 0;
        $failed = $invalid;

        if ($prepared !== []) {
            $summary = $this->repo->importQuestions($id, $prepared);
            $imported = (int) $summary['imported'];
            $skipped = (int) $summary['skipped'];
            $failed += (int) $summary['failed'];
            $results = array_merge($results, $summary['results']);
        }

        usort($results, static fn ($a, $b) => ((int) $a['row']) <=> ((int) $b['row']));

        $message = sprintf(
            'Imported %d question%s. Skipped %d duplicate%s. Failed %d row%s.',
            $imported,
            $imported === 1 ? '' : 's',
            $skipped,
            $skipped === 1 ? '' : 's',
            $failed,
            $failed === 1 ? '' : 's'
        );

        Response::success([
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'total_rows' => count($parsed['rows']),
            'results' => $results,
        ]);
    }

    /**
     * Export a quiz's questions as CSV (teacher/admin)
     * GET /quizzes/{id}/questions/export
     */
    public function exportQuestions(array $user, int $id): void
    {
        $quiz = $this->findManageableQuiz($user, $id, 'You can only export questions from your own quizzes');
        if (!$quiz) {
            return;
        }

        $questions = $this->repo->getQuestions($id);
        $csv = QuestionCsv::serialize($questions);

        $this->streamCsv($csv, 'quiz-' . $id . '-questions-' . date('Y-m-d-His') . '.csv');
    }

    /**
     * Download the import template (teacher/admin)
     * GET /quizzes/{id}/questions/import-template
     */
    public function importTemplate(array $user, int $id): void
    {
        $quiz = $this->findManageableQuiz($user, $id, 'You can only download templates for your own quizzes');
        if (!$quiz) {
            return;
        }

        $this->streamCsv(QuestionCsv::template(), 'quiz-' . $id . '-questions-import-template.csv');
    }

    /**
     * Load a quiz the current user may manage questions for.
     */
    private function findManageableQuiz(array $user, int $quizId, string $forbiddenMessage): ?array
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return null;
        }

        $quiz = $this->repo->findById($quizId);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return null;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return null;
        }

        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden($forbiddenMessage);
            return null;
        }

        return $quiz;
    }

    private function describeUploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The CSV file is too large';
            case UPLOAD_ERR_PARTIAL:
                return 'The CSV file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No CSV file uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server has no temporary folder for uploads';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the uploaded file';
            case UPLOAD_ERR_EXTENSION:
                return 'A server extension stopped the upload';
            default:
                return 'The CSV file could not be uploaded';
        }
    }

    private function streamCsv(string $csv, string $filename): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // UTF-8 BOM so Excel opens non-ASCII text correctly. QuestionCsv::parse
        // strips it again on import.
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /**
     * Update question (teacher/admin)
     * PUT /quiz-questions/{id}
     */
    public function updateQuestion(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $quizId = $this->resolveQuestionQuizId($data);
        $question = $this->repo->findQuestionById($id, $quizId);
        if (!$question) {
            Response::error('Question not found', 404);
            return;
        }

        $isSuperAdmin = AccessControl::isSuperAdmin($user);
        $isAdmin = $roleMiddleware->isAdmin();

        if (!$isSuperAdmin
            && (int) $question['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Question not found', 404);
            return;
        }

        if (!$isSuperAdmin && !$isAdmin) {
            // Bank content belongs to its creator; other teachers must fork
            // rather than edit a colleague's question.
            if ((int) ($question['owner_user_id'] ?? 0) !== (int) ($user['user_id'] ?? 0)) {
                Response::forbidden('You can only edit questions you created');
                return;
            }

            if ($quizId !== null && !empty($question['quiz_id'])) {
                $quiz = $this->repo->findById((int) $question['quiz_id']);
                if (!$quiz) {
                    Response::error('Quiz not found', 404);
                    return;
                }

                if ((int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
                    Response::error('Question not found', 404);
                    return;
                }

                if (($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
                    Response::forbidden('You can only update questions in your own quizzes');
                    return;
                }
            }
        }

        $validator = new Validator($data);
        $validator->max('question_text', 65535)
            ->in('question_type', ['multiple_choice', 'true_false', 'short_answer', 'essay'])
            ->numeric('points')
            ->in('difficulty', ['easy', 'medium', 'hard'])
            ->max('image_question', 255)
            ->max('image_name', 255)
            ->max('correct_answer', 500)
            ->numeric('order_index');

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (array_key_exists('image_name', $data) && !array_key_exists('image_question', $data)) {
            $data['image_question'] = $data['image_name'];
        }

        if (array_key_exists('image_question', $data)) {
            $data['image_question'] = $this->normalizeImageQuestionPath($data['image_question']);
        }

        if (array_key_exists('options', $data) && is_array($data['options'])) {
            $normalizedOptions = [];
            foreach ($data['options'] as $option) {
                $label = strtoupper(trim((string) ($option['label'] ?? '')));
                $text = trim((string) ($option['text'] ?? ''));
                if (!$label || !$text) {
                    continue;
                }

                $normalizedOptions[] = [
                    'label' => $label,
                    'text' => $text,
                    'is_correct' => !empty($option['is_correct']) ? 1 : 0,
                ];
            }

            $data['options'] = $normalizedOptions;
        }

        // Phase 1C: version the edit and return a shared question to review
        // when a non-admin changes its content.
        $data['actor_user_id'] = (int) ($user['user_id'] ?? 0);
        $data['reset_approval_on_edit'] = !($isSuperAdmin || $isAdmin);

        $this->repo->updateQuestion($id, $data, $quizId);

        Response::success(['message' => 'Question updated successfully']);
    }

    /**
     * Resolve the quiz a question request is scoped to.
     *
     * New clients send `quiz_id` (body or query) so a question reused by
     * several quizzes is checked against the right one. Older clients omit it
     * and keep the previous single-quiz behaviour.
     */
    private function resolveQuestionQuizId(array $data = []): ?int
    {
        $candidates = [
            $data['quiz_id'] ?? null,
            $_GET['quiz_id'] ?? null,
            $_GET['quizId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '' || !is_numeric($candidate)) {
                continue;
            }

            $quizId = (int) $candidate;
            if ($quizId > 0) {
                return $quizId;
            }
        }

        return null;
    }

    private function normalizeImageQuestionPath($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = trim($value);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (strpos($path, '/api/uploads/') === 0) {
            return '/api/upload/' . substr($path, strlen('/api/uploads/'));
        }

        if (strpos($path, '/api/upload/') === 0) {
            return $path;
        }

        if (strpos($path, '/uploads/') === 0) {
            return '/api/upload' . substr($path, strlen('/uploads'));
        }

        if (strpos($path, '/') === false) {
            return '/api/upload/quiz-questions/' . $path;
        }

        return $path;
    }

    /**
     * Delete question (teacher/admin)
     * DELETE /quiz-questions/{id}
     */
    public function deleteQuestion(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['admin', 'teacher', 'super_admin'])) {
            return;
        }

        $quizId = $this->resolveQuestionQuizId();
        $question = $this->repo->findQuestionById($id, $quizId);
        if (!$question) {
            Response::error('Question not found', 404);
            return;
        }

        $isSuperAdmin = AccessControl::isSuperAdmin($user);
        $isAdmin = $roleMiddleware->isAdmin();

        if (!$isSuperAdmin
            && (int) $question['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Question not found', 404);
            return;
        }

        $isCreator = (int) ($question['owner_user_id'] ?? 0) === (int) ($user['user_id'] ?? 0);

        if (!$isSuperAdmin && !$isAdmin && !$isCreator) {
            // A teacher who did not create the question may still take it out
            // of a quiz they manage, but may not unlink it everywhere.
            $canManageQuiz = false;

            if (!empty($question['quiz_id'])) {
                $quiz = $this->repo->findById((int) $question['quiz_id']);
                $canManageQuiz = $quiz
                    && (int) $quiz['institution_id'] === AccessControl::institutionId($user)
                    && ($quiz['teacher_id'] ?? 0) == ($user['teacher_id'] ?? 0);
            }

            if (!$canManageQuiz) {
                Response::forbidden('You can only remove questions from your own quizzes');
                return;
            }
        }

        if (!empty($question['quiz_id']) && $quizId === null && !$isSuperAdmin && !$isAdmin && !$isCreator) {
            // Scope the legacy endpoint to the quiz the teacher manages
            // instead of unlinking a shared question from every quiz.
            $quizId = (int) $question['quiz_id'];
        }

        $this->repo->deleteQuestion($id, $quizId);

        Response::success(['message' => 'Question removed from quiz']);
    }

    /**
     * Start quiz (student)
     * POST /quizzes/{id}/start
     */
    public function startQuiz(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check authorization (student must be enrolled)
        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)) {
            Response::error('Quiz not found', 404);
            return;
        }

        // Check if quiz is activated
        if (!$quiz['is_activated']) {
            Response::error('Quiz is not activated', 400);
            return;
        }

        // Check attempts
        $attempts = $this->repo->getStudentAttempts($id, $studentId);
        if (count($attempts) >= $quiz['max_attempts']) {
            Response::error('Maximum attempts reached', 400);
            return;
        }

        $submissionId = $this->repo->startQuiz($id, $studentId);

        // Freeze the question order for this attempt so a refresh, tab switch,
        // or resume never reshuffles the quiz.
        $this->repo->ensureSubmissionQuestionOrder(
            $submissionId,
            $id,
            !empty($quiz['randomize_questions'])
        );

        // Notify student that quiz is in progress
        $this->notifyQuizInProgress($id, $studentId);

        Response::success([
            'message' => 'Quiz started successfully',
            'submission_id' => $submissionId,
            'duration_minutes' => $quiz['duration_minutes']
        ], 201);
    }

    /**
     * Submit quiz (student)
     * POST /quiz-submissions/{id}/submit
     */
    public function submitQuiz(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        // Check if submission exists
        $submission = $this->repo->getSubmissionById($id);

        if (!$submission) {
            Response::error('Submission not found', 404);
            return;
        }

        // Verify ownership
        if ((int) $submission['student_id'] !== (int) $studentId
            || (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Submission not found', 404);
            return;
        }

        // Check if already submitted
        if ($submission['status'] === 'submitted') {
            Response::error('Quiz already submitted', 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Invalid JSON payload', 400);
            return;
        }

        $validator = new Validator($data);
        $validator->required(['answers']);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        // Ensure answers is an array
        if (!is_array($data['answers'])) {
            Response::error('Answers must be an array', 400);
            return;
        }

        // Server-side backstop for "Prevent Going Back": a stale tab cannot
        // change an answer it already moved past, or answer an unreached question.
        $quiz = $this->repo->findById((int) $submission['quiz_id']);
        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        $answerError = $this->repo->validateLockedAnswers(
            $id,
            $data['answers'],
            !empty($quiz['prevent_backtracking'])
        );
        if ($answerError !== null) {
            Response::error($answerError, 400);
            return;
        }

        $this->repo->submitQuiz($id, $data['answers'], $studentId);

        Response::success(['message' => 'Quiz submitted successfully']);

        // Notify student and teacher about quiz submission
        $this->notifyQuizSubmitted($submission['quiz_id'], $studentId);
    }

    /**
     * Get quiz results (student/teacher/admin)
     * GET /quiz-submissions/{id}
     */
    public function getSubmissionResults(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->hasRole(['student', 'teacher', 'admin', 'super_admin'])) {
            Response::forbidden('You do not have access to this submission');
            return;
        }

        // Check if submission exists
        $submission = $this->repo->getSubmissionById($id);

        if (!$submission) {
            Response::error('Submission not found', 404);
            return;
        }

        if ($roleMiddleware->isStudent()) {
            $studentId = $this->resolveStudentId($user);
            if ($studentId === null || (int) $submission['student_id'] !== $studentId) {
                Response::error('Submission not found', 404);
                return;
            }
        } elseif ($roleMiddleware->isAdmin()) {
            if (!AccessControl::isSuperAdmin($user)
                && (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
                Response::error('Submission not found', 404);
                return;
            }
        } elseif ($roleMiddleware->isTeacher()) {
            if (!AccessControl::isSuperAdmin($user)
                && (int) $submission['institution_id'] !== AccessControl::institutionId($user)) {
                Response::error('Submission not found', 404);
                return;
            }

            if ((int) ($submission['teacher_id'] ?? 0) !== (int) ($user['teacher_id'] ?? 0)) {
                Response::error('Submission not found', 404);
                return;
            }
        }

        // Enforce the quiz's show_results policy for students. Teachers and
        // admins always receive the full answer breakdown.
        if ($roleMiddleware->isStudent()) {
            $canReviewAnswers = false;

            if (($submission['status'] ?? '') === 'submitted') {
                $quiz = $this->repo->findById((int) ($submission['quiz_id'] ?? 0));
                $mode = strtolower((string) ($quiz['show_results'] ?? 'after_end'));

                if ($mode === 'instant') {
                    $canReviewAnswers = true;
                } elseif ($mode === 'after_end') {
                    $endDate = trim((string) ($quiz['end_date'] ?? ''));
                    if ($endDate !== '') {
                        try {
                            $canReviewAnswers = new DateTime($endDate) <= new DateTime();
                        } catch (Exception $e) {
                            $canReviewAnswers = false;
                        }
                    }
                }
            }

            if (!$canReviewAnswers) {
                Response::success(array_merge($submission, [
                    'questions' => [],
                    'answers' => [],
                    'review_available' => false,
                ]));
                return;
            }
        }

        $answerDetails = $this->repo->getSubmissionAnswerDetails($id);
        $questions = [];
        $answers = [];

        foreach ($answerDetails as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if ($questionId <= 0) {
                continue;
            }

            $questions[] = [
                'question_id' => $questionId,
                'question_text' => $row['question_text'] ?? null,
                'correct_answer' => $row['correct_answer'] ?? null,
                'explanation' => $row['explanation'] ?? null,
                'question_type' => $row['question_type'] ?? null,
                'points' => $row['points'] ?? null,
                'student_answer' => $row['student_answer'] ?? null,
                'is_correct' => (int) ($row['is_correct'] ?? 0) === 1,
                'points_earned' => $row['points_earned'] ?? null,
            ];
            $answers[(string) $questionId] = (string) ($row['student_answer'] ?? '');
        }

        Response::success(array_merge($submission, [
            'questions' => $questions,
            'answers' => $answers,
        ]));
    }

    /**
     * Get quiz results and analytics (teacher/admin)
     * GET /quizzes/{id}/results
     */
    public function getResults(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['teacher', 'admin', 'super_admin'])) {
            return;
        }

        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if (!AccessControl::isSuperAdmin($user)
            && (int) $quiz['institution_id'] !== AccessControl::institutionId($user)) {
            Response::error('Quiz not found', 404);
            return;
        }

        if ($roleMiddleware->isTeacher() && !$roleMiddleware->isAdmin()
            && ($quiz['teacher_id'] ?? 0) != ($user['teacher_id'] ?? 0)) {
            Response::forbidden('You can only view results for your own quizzes');
            return;
        }

        $summary = $this->repo->getQuizResultsSummary($id);
        $submissions = $this->repo->getQuizSubmissions($id);

        Response::success([
            'quiz' => $quiz,
            'summary' => $summary,
            'submissions' => $submissions,
            'count' => count($submissions)
        ]);
    }

    /**
     * Get student's attempts for a quiz (student)
     * GET /quizzes/{id}/my-attempts
     */
    public function getMyAttempts(array $user, int $id): void
    {
        $roleMiddleware = new RoleMiddleware($user);
        if (!$roleMiddleware->requireRole(['student'])) {
            return;
        }

        $studentId = $this->resolveStudentId($user);
        if ($studentId === null) {
            Response::forbidden('Student profile not found for this account');
            return;
        }

        // Check if quiz exists
        $quiz = $this->repo->findById($id);

        if (!$quiz) {
            Response::error('Quiz not found', 404);
            return;
        }

        if ((int) $quiz['institution_id'] !== AccessControl::institutionId($user)
            || !$this->courseRepo->isStudentEnrolledInCourse((int) $quiz['course_id'], $studentId)) {
            Response::error('Quiz not found', 404);
            return;
        }

        $attempts = $this->repo->getStudentAttempts($id, $studentId);

        Response::success([
            'attempts' => $attempts,
            'count' => count($attempts),
            'max_attempts' => $quiz['max_attempts']
        ]);
    }
}
