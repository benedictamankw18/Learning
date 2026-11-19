<?php

namespace App\Repositories;

use PDO;
use App\Config\Database;
use App\Utils\QuestionVersion;

class QuizRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all quizzes for a course
     */
    public function getCourseQuizzes(int $courseId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                q.*,
                cs.section_name,
                (
                    SELECT COALESCE(SUM(COALESCE(l.points_override, qn.points)), 0)
                    FROM quiz_question_links l
                    INNER JOIN questions qn ON qn.question_id = l.question_id
                    WHERE l.quiz_id = q.quiz_id
                ) AS total_points,
                (
                    SELECT COUNT(*)
                    FROM quiz_question_links l
                    WHERE l.quiz_id = q.quiz_id
                ) AS question_count
            FROM quizzes q
            LEFT JOIN course_sections cs ON q.section_id = cs.course_sections_id
            WHERE q.course_id = :course_id
            ORDER BY q.start_date DESC, q.created_at DESC
        ");

        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find quiz by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                q.*,
                c.institution_id,
                c.teacher_id,
                cs.section_name,
                (
                    SELECT COALESCE(SUM(COALESCE(l.points_override, qn.points)), 0)
                    FROM quiz_question_links l
                    INNER JOIN questions qn ON qn.question_id = l.question_id
                    WHERE l.quiz_id = q.quiz_id
                ) AS total_points,
                (
                    SELECT COUNT(*)
                    FROM quiz_question_links l
                    WHERE l.quiz_id = q.quiz_id
                ) AS question_count
            FROM quizzes q
            INNER JOIN class_subjects c ON q.course_id = c.course_id
            LEFT JOIN course_sections cs ON q.section_id = cs.course_sections_id
            WHERE q.quiz_id = :id
        ");

        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new quiz
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO quizzes (
                course_id,
                section_id,
                title,
                description,
                duration_minutes,
                max_attempts,
                status,
                quiz_type,
                is_activated,
                show_results,
                randomize_questions,
                prevent_backtracking,
                start_date,
                end_date
            ) VALUES (
                :course_id,
                :section_id,
                :title,
                :description,
                :duration_minutes,
                :max_attempts,
                :status,
                :quiz_type,
                :is_activated,
                :show_results,
                :randomize_questions,
                :prevent_backtracking,
                :start_date,
                :end_date
            )
        ");

        $stmt->execute([
            'course_id' => $data['course_id'],
            'section_id' => $data['section_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'],
            'max_attempts' => $data['max_attempts'] ?? 1,
            'status' => $data['status'] ?? 'draft',
            'quiz_type' => $data['quiz_type'] ?? 'graded',
            'is_activated' => $data['is_activated'] ?? 0,
            'show_results' => $data['show_results'] ?? 'after_end',
            'randomize_questions' => $data['randomize_questions'] ?? 0,
            'prevent_backtracking' => $data['prevent_backtracking'] ?? 0,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a quiz
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'section_id',
            'title',
            'description',
            'duration_minutes',
            'max_attempts',
            'status',
            'quiz_type',
            'is_activated',
            'show_results',
            'randomize_questions',
            'prevent_backtracking',
            'start_date',
            'end_date'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";

        $sql = "UPDATE quizzes SET " . implode(', ', $fields) . " WHERE quiz_id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete a quiz
     */
    public function delete(int $id): bool
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Links belong to the quiz, so they go with it. The bank rows
            // themselves are kept: they may still be used by another quiz.
            $this->db->prepare('DELETE FROM quiz_question_links WHERE quiz_id = :id')
                ->execute(['id' => $id]);

            $deleted = $this->db->prepare('DELETE FROM quizzes WHERE quiz_id = :id')
                ->execute(['id' => $id]);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $deleted;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get questions for a quiz
     */
    public function getQuestions(int $quizId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                qn.question_id,
                qn.question_text,
                qn.image_question,
                qn.question_type,
                COALESCE(l.points_override, qn.points) AS points,
                qn.difficulty,
                qn.explanation,
                qn.correct_answer,
                l.position AS order_index,
                qn.current_version_id,
                qn.visibility,
                qn.approval_status,
                qn.created_at,
                qn.updated_at
            FROM quiz_question_links l
            INNER JOIN questions qn ON qn.question_id = l.question_id
            WHERE l.quiz_id = :quiz_id
            ORDER BY l.position ASC, qn.question_id ASC
        ");

        $stmt->execute(['quiz_id' => $quizId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$questions) {
            return [];
        }

        foreach ($questions as &$question) {
            $optStmt = $this->db->prepare("
                SELECT option_label, option_text, is_correct
                FROM question_bank_options
                WHERE question_id = :question_id
                ORDER BY option_label ASC
            ");
            $optStmt->execute(['question_id' => (int) $question['question_id']]);
            $question['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($question);

        return $questions;
    }

    /**
     * Get the persisted question order for a submission.
     *
     * @return array<int,int>
     */
    public function getSubmissionQuestionOrder(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT question_id
            FROM quiz_submission_questions
            WHERE submission_id = :submission_id
            ORDER BY position ASC, question_id ASC
        ");

        $stmt->execute(['submission_id' => $submissionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Answers captured as the student leaves each question.
     *
     * @return array<int,array{question_id:int,locked:bool,answer:?string}>
     */
    public function getSubmissionAnswerLocks(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT position, question_id, locked_answer, answer_locked
            FROM quiz_submission_questions
            WHERE submission_id = :submission_id
            ORDER BY position ASC
        ");
        $stmt->execute(['submission_id' => $submissionId]);

        $locks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locks[(int) $row['position']] = [
                'question_id' => (int) $row['question_id'],
                'locked' => (int) $row['answer_locked'] === 1,
                'answer' => $row['locked_answer'],
            ];
        }

        return $locks;
    }

    /**
     * Record how far a student has progressed in an attempt and capture the
     * answer they are leaving behind.
     *
     * A locked quiz requires an answer before the student may leave a question,
     * never overwrites an answer that was already captured, and never allows a
     * jump to a question more than one step ahead. An unlocked quiz may
     * overwrite its captured answers so going back is still legal.
     *
     * @return array{furthest_position:int,answer_locked:bool,error:?string}
     */
    public function recordSubmissionProgress(
        int $submissionId,
        int $position,
        int $nextPosition,
        string $answer,
        bool $preventBacktracking
    ): array {
        $stmt = $this->db->prepare("
            SELECT furthest_position
            FROM quiz_submissions
            WHERE submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['furthest_position' => 0, 'answer_locked' => false, 'error' => 'Submission not found'];
        }

        $furthest = (int) $row['furthest_position'];
        $locks = $this->getSubmissionAnswerLocks($submissionId);
        $legacyAttempt = $furthest === 0 && !$this->hasCapturedAnswers($locks);

        if ($preventBacktracking && trim($answer) === '') {
            return [
                'furthest_position' => $furthest,
                'answer_locked' => false,
                'error' => 'An answer is required before moving to the next question',
            ];
        }

        // Attempts started before progress tracking have no history. Let them
        // catch up once instead of trapping the student on question 1.
        if ($preventBacktracking && !$legacyAttempt && $nextPosition > $furthest + 1) {
            return [
                'furthest_position' => $furthest,
                'answer_locked' => false,
                'error' => 'Question navigation is disabled for this quiz',
            ];
        }

        $newFurthest = max($furthest, $position, $nextPosition);
        $questionIds = $this->getSubmissionQuestionOrder($submissionId);
        $questionId = $questionIds[$position] ?? null;
        $answerLocked = false;

        if ($questionId !== null) {
            $lock = $this->db->prepare("
                UPDATE quiz_submission_questions
                SET locked_answer = :answer, answer_locked = 1
                WHERE submission_id = :submission_id
                  AND question_id = :question_id
                  AND (:overwrite = 1 OR answer_locked = 0)
            ");
            $lock->execute([
                'answer' => $answer,
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'overwrite' => $preventBacktracking ? 0 : 1,
            ]);
            $answerLocked = $lock->rowCount() > 0;
        }

        $advance = $this->db->prepare("
            UPDATE quiz_submissions
            SET furthest_position = GREATEST(furthest_position, :furthest)
            WHERE submission_id = :submission_id
        ");
        $advance->execute([
            'furthest' => $newFurthest,
            'submission_id' => $submissionId,
        ]);

        return ['furthest_position' => $newFurthest, 'answer_locked' => $answerLocked, 'error' => null];
    }

    /**
     * Server-side backstop for "Prevent Going Back".
     *
     * Rejects answers to questions that were never reached and answers that
     * were changed after the student moved on. Blank captures left by older
     * clients carry no usable answer, so they are skipped. Returns null when
     * the answers are acceptable.
     */
    public function validateLockedAnswers(int $submissionId, array $answers, bool $preventBacktracking): ?string
    {
        if (!$preventBacktracking) {
            return null;
        }

        // An attempt that began before progress tracking has no captured
        // answers to compare against, so it must not be blocked retroactively.
        $stmt = $this->db->prepare("
            SELECT furthest_position
            FROM quiz_submissions
            WHERE submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $furthest = $row ? (int) $row['furthest_position'] : 0;

        $positions = array_flip($this->getSubmissionQuestionOrder($submissionId));
        $locks = $this->getSubmissionAnswerLocks($submissionId);

        if ($furthest === 0 && !$this->hasCapturedAnswers($locks)) {
            return null;
        }

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $questionId = (int) ($answer['question_id'] ?? 0);
            if (!array_key_exists($questionId, $positions)) {
                continue;
            }

            $position = $positions[$questionId];
            if ($position > $furthest) {
                return 'This answer belongs to a question that was not reached in this attempt.';
            }

            if (!isset($locks[$position]) || !$locks[$position]['locked']) {
                continue;
            }

            $captured = $this->normalizeLockedAnswer($locks[$position]['answer']);
            if ($captured === '') {
                // Older clients captured blank answers before answer-before-next
                // existed. There is no usable answer to compare against, so
                // treat the position as unlocked instead of blocking submit.
                continue;
            }

            $submitted = $this->normalizeLockedAnswer($answer['answer'] ?? '');
            if ($submitted !== $captured) {
                return 'You cannot change an answer after moving to the next question.';
            }
        }

        return null;
    }

    /**
     * @param array<int,array{question_id:int,locked:bool,answer:?string}> $locks
     */
    private function hasCapturedAnswers(array $locks): bool
    {
        foreach ($locks as $lock) {
            if ($lock['locked']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function normalizeLockedAnswer($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';
    }

    /**
     * Return the ordered question IDs for an attempt, creating and persisting
     * the order on first use.
     *
     * The order is frozen per submission: a refresh, tab switch, or resume
     * returns the same sequence, while a new attempt gets a new order.
     *
     * @return array<int,int>
     */
    public function ensureSubmissionQuestionOrder(int $submissionId, int $quizId, bool $shuffle): array
    {
        $existing = $this->getSubmissionQuestionOrder($submissionId);
        if (!empty($existing)) {
            return $existing;
        }

        $questions = $this->getQuestions($quizId);
        if (empty($questions)) {
            return [];
        }

        $questionsById = [];
        foreach ($questions as $question) {
            $questionsById[(int) $question['question_id']] = $question;
        }

        $questionIds = array_keys($questionsById);

        if ($shuffle) {
            $questionIds = $this->shuffleQuestionOrder($questionIds);
        }

        $insert = $this->db->prepare("
            INSERT IGNORE INTO quiz_submission_questions (
                submission_id,
                question_id,
                position,
                question_snapshot,
                question_version_id
            ) VALUES (
                :submission_id,
                :question_id,
                :position,
                :question_snapshot,
                :question_version_id
            )
        ");

        foreach ($questionIds as $position => $questionId) {
            $insert->execute([
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'position' => $position,
                'question_snapshot' => $this->buildQuestionSnapshot($questionsById[$questionId]),
                'question_version_id' => $questionsById[$questionId]['current_version_id'] ?? null,
            ]);
        }

        // Read back so two concurrent first requests converge on the order
        // that was actually persisted rather than on their local shuffle.
        $persisted = $this->getSubmissionQuestionOrder($submissionId);

        return !empty($persisted) ? $persisted : $questionIds;
    }

    /**
     * Fisher-Yates shuffle that never returns the authored order unchanged.
     * A "shuffle" that leaves every question in place looks broken to
     * students, so rotate once when that happens.
     *
     * @param array<int,int> $questionIds
     * @return array<int,int>
     */
    private function shuffleQuestionOrder(array $questionIds): array
    {
        $authored = array_values($questionIds);
        $shuffled = $authored;
        shuffle($shuffled);

        if (count($shuffled) > 1 && $shuffled === $authored) {
            $shuffled[] = array_shift($shuffled);
        }

        return $shuffled;
    }

    /**
     * Get the questions for an attempt in the student's persisted order.
     *
     * The served content wins so an attempt keeps the wording, answer key and
     * points it was served with even if the bank row is edited later. Phase 1C
     * attempts pin an immutable question version; legacy Phase 1B attempts
     * only have a JSON snapshot. Questions with neither (attempts started
     * before phase 1B) fall back to the current bank content.
     */
    public function getSubmissionQuestions(int $submissionId, int $quizId, bool $shuffle): array
    {
        $questionIds = $this->ensureSubmissionQuestionOrder($submissionId, $quizId, $shuffle);
        if (empty($questionIds)) {
            return [];
        }

        $bankQuestions = [];
        foreach ($this->getQuestions($quizId) as $question) {
            $bankQuestions[(int) $question['question_id']] = $question;
        }

        $served = $this->getServedQuestionContent($submissionId);

        $ordered = [];
        foreach ($questionIds as $position => $questionId) {
            $questionId = (int) $questionId;

            if (isset($served[$questionId])) {
                $content = $served[$questionId];
                $content['order_index'] = $position;
                $ordered[] = $content;
                continue;
            }

            if (isset($bankQuestions[$questionId])) {
                $question = $bankQuestions[$questionId];
                $question['order_index'] = $position;
                $ordered[] = $question;
            }
        }

        return $ordered;
    }

    /**
     * Resolve the exact content each question was served with in an attempt.
     *
     * Priority:
     *  1. Phase 1C immutable version (question_version_id), which protects a
     *     submitted attempt from later bank edits, including approval resets;
     *  2. Phase 1B JSON snapshot (question_snapshot);
     *  3. nothing (caller falls back to live bank content).
     *
     * The snapshot's points are authoritative when both exist because it
     * captured the per-quiz points override at serve time, while a version
     * only stores the bank default.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getServedQuestionContent(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT question_id, question_snapshot, question_version_id
            FROM quiz_submission_questions
            WHERE submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submissionId]);

        $bankRepo = new QuestionBankRepository();
        $served = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versionContent = null;
            if (!empty($row['question_version_id'])) {
                $versionContent = $bankRepo->findVersionContent((int) $row['question_version_id']);
            }
            $snapshot = $this->decodeQuestionSnapshot($row['question_snapshot'] ?? null);

            $content = $versionContent ?? $snapshot;
            if ($content === null) {
                continue;
            }

            if ($versionContent !== null && $snapshot !== null) {
                $content['points'] = $snapshot['points'] ?? $content['points'];
            }

            $served[(int) $row['question_id']] = $content;
        }

        return $served;
    }

    /**
     * Serialize the question content an attempt was served with.
     *
     * Uses the same shape as immutable question versions so serving, review,
     * and grading all read one normalized format.
     */
    private function buildQuestionSnapshot(array $question): string
    {
        return QuestionVersion::encode($question);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeQuestionSnapshot(?string $raw): ?array
    {
        return QuestionVersion::decode($raw);
    }

    /**
     * Get a bank question with its options.
     */
    public function getQuestionWithOptions(int $questionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM questions
            WHERE question_id = :id
        ");

        $stmt->execute(['id' => $questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM question_bank_options
            WHERE question_id = :question_id
            ORDER BY option_label ASC
        ");

        $stmt->execute(['question_id' => $questionId]);
        $question['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $question;
    }

    /**
     * Find a bank question by ID.
     *
     * The returned row carries the `quiz_id` used to authorize the request.
     * When the caller knows which quiz it is acting on it should pass it so a
     * question reused by several quizzes is checked against the right one.
     */
    public function findQuestionById(int $questionId, ?int $quizId = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM questions
            WHERE question_id = :id
        ");

        $stmt->execute(['id' => $questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question) {
            return null;
        }

        if ($quizId !== null) {
            // A caller that names a quiz must only see the question through
            // that quiz. Returning the bank row when it is not linked would
            // let a request scoped to the wrong quiz succeed.
            $linkedQuizId = $this->findLinkedQuizId($questionId, $quizId);
            if ($linkedQuizId === null) {
                return null;
            }
            $question['quiz_id'] = $linkedQuizId;

            return $question;
        }

        $question['quiz_id'] = $this->findLinkedQuizId($questionId);

        return $question;
    }

    /**
     * Resolve the quiz a question is being managed through.
     *
     * Returns null when the requested quiz does not link the question, which
     * lets callers treat a cross-quiz request as "not found" instead of
     * leaking that the bank row exists.
     */
    private function findLinkedQuizId(int $questionId, ?int $preferredQuizId = null): ?int
    {
        if ($preferredQuizId !== null) {
            $stmt = $this->db->prepare("
                SELECT quiz_id
                FROM quiz_question_links
                WHERE question_id = :question_id AND quiz_id = :quiz_id
                LIMIT 1
            ");
            $stmt->execute(['question_id' => $questionId, 'quiz_id' => $preferredQuizId]);
            $found = $stmt->fetchColumn();

            return $found === false ? null : (int) $found;
        }

        $stmt = $this->db->prepare("
            SELECT quiz_id
            FROM quiz_question_links
            WHERE question_id = :question_id
            ORDER BY quiz_id ASC
            LIMIT 1
        ");
        $stmt->execute(['question_id' => $questionId]);
        $found = $stmt->fetchColumn();

        return $found === false ? null : (int) $found;
    }

    /**
     * Add a question to the bank and link it to a quiz.
     *
     * The bank row is created once; the link carries the quiz-specific order.
     * `owner_user_id` follows the quiz's teacher, so a question authored for a
     * course with no teacher is institution-owned.
     */
    public function addQuestion(array $data): int
    {
        $quizId = (int) ($data['quiz_id'] ?? 0);
        if ($quizId <= 0) {
            throw new \InvalidArgumentException('quiz_id is required');
        }

        $context = $this->getQuizQuestionContext($quizId);
        if ($context === null) {
            throw new \RuntimeException('Quiz not found');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $imageQuestion = $data['image_question'] ?? ($data['image_name'] ?? null);
            $points = (int) ($data['points'] ?? 1);

            $stmt = $this->db->prepare("
                INSERT INTO questions (
                    institution_id,
                    owner_user_id,
                    question_text,
                    image_question,
                    question_type,
                    points,
                    difficulty,
                    explanation,
                    correct_answer
                ) VALUES (
                    :institution_id,
                    :owner_user_id,
                    :question_text,
                    :image_question,
                    :question_type,
                    :points,
                    :difficulty,
                    :explanation,
                    :correct_answer
                )
            ");

            $stmt->execute([
                'institution_id' => $context['institution_id'],
                'owner_user_id' => $context['owner_user_id'],
                'question_text' => $data['question_text'],
                'image_question' => $imageQuestion,
                'question_type' => $data['question_type'],
                'points' => $points,
                'difficulty' => $data['difficulty'] ?? 'medium',
                'explanation' => $data['explanation'] ?? null,
                'correct_answer' => $data['correct_answer'] ?? null,
            ]);

            $questionId = (int) $this->db->lastInsertId();

            if (!empty($data['options']) && is_array($data['options'])) {
                foreach ($data['options'] as $option) {
                    $this->addQuestionOption($questionId, $option);
                }
            }

            $position = array_key_exists('order_index', $data)
                ? (int) $data['order_index']
                : $this->nextQuestionPosition($quizId);

            $this->linkQuestionToQuiz(
                $quizId,
                $questionId,
                $position,
                array_key_exists('points_override', $data) ? (int) $data['points_override'] : null
            );

            // Phase 1C: freeze version 1 so attempts can pin the content they
            // were served even if the bank row is edited later.
            (new QuestionBankRepository())->createVersion(
                $questionId,
                isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $questionId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Link an existing visible bank question to a quiz.
     *
     * This never changes bank content or ownership; it only adds the quiz
     * link. Visibility is authorized by the controller before calling here.
     */
    public function linkExistingQuestion(int $quizId, int $questionId): bool
    {
        $context = $this->getQuizQuestionContext($quizId);
        if ($context === null) {
            throw new \RuntimeException('Quiz not found');
        }

        $stmt = $this->db->prepare("
            SELECT question_id
            FROM questions
            WHERE question_id = :question_id
              AND institution_id = :institution_id
              AND is_archived = 0
        ");
        $stmt->execute([
            'question_id' => $questionId,
            'institution_id' => $context['institution_id'],
        ]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        $exists = $this->db->prepare("
            SELECT COUNT(*) FROM quiz_question_links
            WHERE quiz_id = :quiz_id AND question_id = :question_id
        ");
        $exists->execute(['quiz_id' => $quizId, 'question_id' => $questionId]);
        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        $this->linkQuestionToQuiz($quizId, $questionId, $this->nextQuestionPosition($quizId));

        return true;
    }

    /**
     * Resolve the institution and question owner for a quiz.
     *
     * @return array{institution_id:int,owner_user_id:?int}|null
     */
    private function getQuizQuestionContext(int $quizId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                cs.institution_id,
                t.user_id AS owner_user_id
            FROM quizzes q
            INNER JOIN class_subjects cs ON cs.course_id = q.course_id
            LEFT JOIN teachers t ON t.teacher_id = cs.teacher_id
            WHERE q.quiz_id = :quiz_id
        ");

        $stmt->execute(['quiz_id' => $quizId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'institution_id' => (int) $row['institution_id'],
            'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
        ];
    }

    private function nextQuestionPosition(int $quizId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(position), -1) + 1
            FROM quiz_question_links
            WHERE quiz_id = :quiz_id
        ");
        $stmt->execute(['quiz_id' => $quizId]);

        return (int) $stmt->fetchColumn();
    }

    private function linkQuestionToQuiz(int $quizId, int $questionId, int $position, ?int $pointsOverride = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO quiz_question_links (
                quiz_id,
                question_id,
                position,
                points_override
            ) VALUES (
                :quiz_id,
                :question_id,
                :position,
                :points_override
            ) ON DUPLICATE KEY UPDATE
                position = VALUES(position),
                points_override = COALESCE(VALUES(points_override), points_override)
        ");

        $stmt->execute([
            'quiz_id' => $quizId,
            'question_id' => $questionId,
            'position' => $position,
            'points_override' => $pointsOverride,
        ]);
    }

    /**
     * Add option to a bank question.
     */
    public function addQuestionOption(int $questionId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO question_bank_options (
                question_id,
                option_label,
                option_text,
                is_correct
            ) VALUES (
                :question_id,
                :option_label,
                :option_text,
                :is_correct
            )
        ");

        $stmt->execute([
            'question_id' => $questionId,
            'option_label' => $data['label'],
            'option_text' => $data['text'],
            'is_correct' => $data['is_correct'] ?? 0
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replace all options for a bank question.
     */
    public function replaceQuestionOptions(int $questionId, array $options): void
    {
        $deleteStmt = $this->db->prepare("
            DELETE FROM question_bank_options
            WHERE question_id = :question_id
        ");
        $deleteStmt->execute(['question_id' => $questionId]);

        foreach ($options as $option) {
            $this->addQuestionOption($questionId, $option);
        }
    }

    /**
     * Append pre-validated questions to a quiz.
     *
     * Duplicate question text is skipped so re-importing the same file is
     * idempotent. A matching row that already exists in the teacher's bank is
     * linked instead of duplicated, and only genuinely new text creates a bank
     * row. Each insert runs inside a savepoint, so one bad row cannot discard
     * the rows that already succeeded.
     *
     * @param array<int,array{row:int,question:array<string,mixed>}> $rows
     * @return array{imported:int,skipped:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public function importQuestions(int $quizId, array $rows): array
    {
        $context = $this->getQuizQuestionContext($quizId);
        if ($context === null) {
            throw new \RuntimeException('Quiz not found');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        try {
            $nextOrder = $this->nextQuestionPosition($quizId);

            $stmt = $this->db->prepare("
                SELECT qn.question_text
                FROM quiz_question_links l
                INNER JOIN questions qn ON qn.question_id = l.question_id
                WHERE l.quiz_id = :quiz_id
            ");
            $stmt->execute(['quiz_id' => $quizId]);

            $existing = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $text) {
                $existing[$this->normalizeQuestionTextKey((string) $text)] = true;
            }

            $findExisting = $this->prepareBankTextLookup($context);

            foreach ($rows as $index => $row) {
                $rowNumber = (int) ($row['row'] ?? 0);
                $question = is_array($row['question'] ?? null) ? $row['question'] : [];
                $text = (string) ($question['question_text'] ?? '');
                $key = $this->normalizeQuestionTextKey($text);

                if ($text === '') {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'message' => 'question_text is required.',
                    ];
                    continue;
                }

                if (isset($existing[$key])) {
                    $skipped++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'skipped',
                        'message' => 'A question with this text already exists in this quiz.',
                    ];
                    continue;
                }

                $savepoint = 'q_import_' . (int) $index;
                $this->db->exec('SAVEPOINT ' . $savepoint);

                try {
                    $params = [
                        'institution_id' => $context['institution_id'],
                        'question_text' => trim($text),
                    ];
                    if ($context['owner_user_id'] !== null) {
                        $params['owner_user_id'] = $context['owner_user_id'];
                    }
                    $findExisting->execute($params);
                    $bankQuestionId = $findExisting->fetchColumn();

                    if ($bankQuestionId !== false) {
                        // Reuse the existing bank row: the question text is
                        // already maintained by this teacher (or the
                        // institution), so importing it only adds a link.
                        $this->linkQuestionToQuiz($quizId, (int) $bankQuestionId, $nextOrder);
                        $message = 'Question imported from the existing bank.';
                    } else {
                        $question['quiz_id'] = $quizId;
                        $question['order_index'] = $nextOrder;
                        $this->addQuestion($question);
                        $message = 'Question imported.';
                    }

                    $existing[$key] = true;
                    $nextOrder++;
                    $imported++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'imported',
                        'message' => $message,
                    ];

                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (\Throwable $e) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);

                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'message' => 'Could not save this question.',
                    ];
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Lookup used by CSV import to find a bank row the importer may reuse.
     *
     * Reuse is limited to questions the teacher can actually see: their own
     * rows, or rows shared with the institution that an administrator has
     * approved. Drafts, pending submissions, rejected and archived rows are
     * never borrowed, so an import cannot silently depend on unvetted content.
     */
    private function prepareBankTextLookup(array $context): \PDOStatement
    {
        $shared = "(visibility = 'institution' AND approval_status = 'approved')";
        $visibility = $context['owner_user_id'] !== null
            ? "(owner_user_id = :owner_user_id OR {$shared})"
            : $shared;

        $sql = "
            SELECT question_id
            FROM questions
            WHERE institution_id = :institution_id
              AND is_archived = 0
              AND TRIM(question_text) = :question_text
              AND {$visibility}
            ORDER BY question_id ASC
            LIMIT 1
        ";

        return $this->db->prepare($sql);
    }

    private function normalizeQuestionTextKey(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * Update a bank question.
     *
     * Bank content is shared, so edits here are visible everywhere the
     * question is used. Quiz-scoped fields are different: when `$quizId` is
     * given, `order_index` moves this quiz's link and `points` becomes the
     * per-quiz override instead of changing the bank default.
     */
    public function updateQuestion(int $questionId, array $data, ?int $quizId = null): bool
    {
        if (array_key_exists('image_name', $data) && !array_key_exists('image_question', $data)) {
            $data['image_question'] = $data['image_name'];
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $fields = [];
            $params = ['question_id' => $questionId];

            $allowedFields = [
                'question_text',
                'image_question',
                'question_type',
                'difficulty',
                'explanation',
                'correct_answer',
            ];

            // A points change made from a quiz applies to that quiz only.
            if (!($quizId !== null && array_key_exists('points', $data))) {
                $allowedFields[] = 'points';
            }

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }

            if (!empty($fields)) {
                $fields[] = 'updated_at = NOW()';
                $sql = "UPDATE questions SET " . implode(', ', $fields) . " WHERE question_id = :question_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }

            if (array_key_exists('options', $data) && is_array($data['options'])) {
                $this->replaceQuestionOptions($questionId, $data['options']);
            }

            if ($quizId !== null) {
                $linkFields = [];
                $linkParams = ['quiz_id' => $quizId, 'question_id' => $questionId];

                if (array_key_exists('order_index', $data)) {
                    $linkFields[] = 'position = :position';
                    $linkParams['position'] = (int) $data['order_index'];
                }

                if (array_key_exists('points', $data)) {
                    $linkFields[] = 'points_override = :points_override';
                    $linkParams['points_override'] = (int) $data['points'];
                }

                if (!empty($linkFields)) {
                    $sql = "UPDATE quiz_question_links SET " . implode(', ', $linkFields)
                        . " WHERE quiz_id = :quiz_id AND question_id = :question_id";
                    $this->db->prepare($sql)->execute($linkParams);
                }
            }

            // Phase 1C: freeze a new immutable version whenever answerable
            // bank content changes. A quiz-scoped points or order edit only
            // touches the link, so it must not create a version.
            $contentFields = [
                'question_text', 'image_question', 'question_type',
                'difficulty', 'explanation', 'correct_answer',
            ];
            if ($quizId === null) {
                $contentFields[] = 'points';
            }
            $contentChanged = false;
            foreach ($contentFields as $field) {
                if (array_key_exists($field, $data)) {
                    $contentChanged = true;
                    break;
                }
            }
            if (array_key_exists('options', $data) && is_array($data['options'])) {
                $contentChanged = true;
            }

            if ($contentChanged) {
                $bankRepo = new QuestionBankRepository();
                $actorId = isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null;
                $bankRepo->createVersion($questionId, $actorId);

                // A non-admin edit to a shared question returns it to review
                // so the institution copy is not silently changed.
                if (!empty($data['reset_approval_on_edit'])) {
                    $bankRepo->submitForApproval($questionId, (int) ($actorId ?? 0));
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Remove a question from a quiz without deleting bank content.
     *
     * Without `$quizId` every link is removed, which matches the legacy
     * "delete question" endpoint. The bank row itself is always kept: it may
     * be reused by another quiz and past attempts keep their snapshot.
     */
    public function deleteQuestion(int $questionId, ?int $quizId = null): bool
    {
        if ($quizId !== null) {
            $stmt = $this->db->prepare("
                DELETE FROM quiz_question_links
                WHERE quiz_id = :quiz_id AND question_id = :question_id
            ");

            $stmt->execute(['quiz_id' => $quizId, 'question_id' => $questionId]);

            return $stmt->rowCount() > 0;
        }

        $stmt = $this->db->prepare("
            DELETE FROM quiz_question_links
            WHERE question_id = :question_id
        ");

        $stmt->execute(['question_id' => $questionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Start quiz (create submission)
     */
    public function startQuiz(int $quizId, int $studentId): int
    {
        // Get current attempt number
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(attempt), 0) as last_attempt
            FROM quiz_submissions
            WHERE quiz_id = :quiz_id AND student_id = :student_id
        ");

        $stmt->execute([
            'quiz_id' => $quizId,
            'student_id' => $studentId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempt = $result['last_attempt'] + 1;

        // Create submission
        $stmt = $this->db->prepare("
            INSERT INTO quiz_submissions (
                quiz_id,
                student_id,
                attempt,
                status
            ) VALUES (
                :quiz_id,
                :student_id,
                :attempt,
                'in_progress'
            )
        ");

        $stmt->execute([
            'quiz_id' => $quizId,
            'student_id' => $studentId,
            'attempt' => $attempt
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Submit quiz
     */
    public function submitQuiz(int $submissionId, array $answers, int $studentId): bool
    {
        // Get quiz details
        $stmt = $this->db->prepare("
            SELECT q.*, qs.quiz_id
            FROM quiz_submissions qs
            INNER JOIN quizzes q ON qs.quiz_id = q.quiz_id
            WHERE qs.submission_id = :submission_id
        ");

        $stmt->execute(['submission_id' => $submissionId]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            return false;
        }

        $totalScore = 0;
        $maxScore = 0;

        // Grade against the content each question was served with, not against
        // a bank edit made while the student was answering. Phase 1C versions
        // take priority; legacy attempts fall back to their JSON snapshot.
        $served = $this->getServedQuestionContent($submissionId);

        // Process each answer
        foreach ($answers as $answer) {
            $questionId = $answer['question_id'];
            $studentAnswer = $answer['answer'];

            // Get the bank question and this quiz's points override.
            $stmt = $this->db->prepare("
                SELECT qn.*, COALESCE(l.points_override, qn.points) AS points
                FROM quiz_question_links l
                INNER JOIN questions qn ON qn.question_id = l.question_id
                WHERE l.quiz_id = :quiz_id AND l.question_id = :question_id
            ");
            $stmt->execute([
                'quiz_id' => $quiz['quiz_id'],
                'question_id' => $questionId,
            ]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$question) {
                continue;
            }

            $servedContent = $served[(int) $questionId] ?? null;
            if ($servedContent !== null) {
                $question['correct_answer'] = $servedContent['correct_answer'] ?? $question['correct_answer'];
                $question['question_type'] = $servedContent['question_type'] ?? $question['question_type'];
                $question['points'] = $servedContent['points'] ?? $question['points'];
            }

            $maxScore += (int) $question['points'];
            $normalizedStudentAnswer = $this->normalizeAnswerForComparison((string) $studentAnswer, (string) ($question['question_type'] ?? ''));
            $normalizedCorrectAnswer = $this->normalizeAnswerForComparison((string) ($question['correct_answer'] ?? ''), (string) ($question['question_type'] ?? ''));
            $isCorrect = strcasecmp($normalizedStudentAnswer, $normalizedCorrectAnswer) === 0;
            $pointsEarned = $isCorrect ? $question['points'] : 0;
            $totalScore += $pointsEarned;

            // Save answer
            $stmt = $this->db->prepare("
                INSERT INTO quiz_submission_answers (
                    submission_id,
                    question_id,
                    answer,
                    is_correct,
                    points_earned
                ) VALUES (
                    :submission_id,
                    :question_id,
                    :answer,
                    :is_correct,
                    :points_earned
                )
            ");

            $stmt->execute([
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'answer' => $studentAnswer,
                // Bind an integer: PDO::execute() sends booleans as strings,
                // so false would become '' and strict-mode MySQL/MariaDB
                // rejects that value for a tinyint column.
                'is_correct' => $isCorrect ? 1 : 0,
                'points_earned' => $pointsEarned
            ]);
        }

        // Update submission
        $stmt = $this->db->prepare("
            UPDATE quiz_submissions
            SET 
                score = :score,
                max_score = :max_score,
                status = 'submitted',
                submitted_at = NOW(),
                graded_at = NOW(),
                updated_at = NOW()
            WHERE submission_id = :id
        ");

        return $stmt->execute([
            'id' => $submissionId,
            'score' => $totalScore,
            'max_score' => $maxScore
        ]);
    }

    /**
     * Normalize answer format before comparison.
     * True/False questions may come in as A/B while correct answers are stored as true/false.
     */
    private function normalizeAnswerForComparison(string $value, string $questionType): string
    {
        $normalizedType = strtolower(trim($questionType));
        $normalizedValue = strtolower(trim($value));

        if ($normalizedType !== 'true_false') {
            return $normalizedValue;
        }

        if (in_array($normalizedValue, ['a', 'true', '1', 't', 'yes'], true)) {
            return 'true';
        }

        if (in_array($normalizedValue, ['b', 'false', '0', 'f', 'no'], true)) {
            return 'false';
        }

        return $normalizedValue;
    }

    /**
     * Get submission by ID
     */
    public function getSubmissionById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                qs.*,
                q.course_id,
                c.institution_id,
                c.teacher_id,
                s.student_id_number,
                CONCAT(u.first_name, ' ', u.last_name) as student_name
            FROM quiz_submissions qs
            INNER JOIN quizzes q ON qs.quiz_id = q.quiz_id
            INNER JOIN class_subjects c ON q.course_id = c.course_id
            INNER JOIN students s ON qs.student_id = s.student_id
            LEFT JOIN users u ON u.user_id = s.user_id
            WHERE qs.submission_id = :id
        ");

        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get submission answers joined with question metadata for review UI.
     *
     * The attempt snapshot takes priority so a review still shows the wording
     * and answer key the student was actually served after a bank edit.
     */
    public function getSubmissionAnswerDetails(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                sa.question_id,
                sa.answer AS student_answer,
                sa.is_correct,
                sa.points_earned,
                sqq.position,
                sqq.question_snapshot,
                l.position AS link_position,
                COALESCE(l.points_override, qn.points) AS points,
                qn.question_text,
                qn.correct_answer,
                qn.explanation,
                qn.question_type
            FROM quiz_submission_answers sa
            LEFT JOIN quiz_submission_questions sqq
                ON sqq.submission_id = sa.submission_id
               AND sqq.question_id = sa.question_id
            LEFT JOIN quiz_submissions sub ON sub.submission_id = sa.submission_id
            LEFT JOIN quiz_question_links l
                ON l.quiz_id = sub.quiz_id
               AND l.question_id = sa.question_id
            LEFT JOIN questions qn ON qn.question_id = sa.question_id
            WHERE sa.submission_id = :submission_id
            ORDER BY COALESCE(sqq.position, l.position) ASC, sa.question_id ASC
        ");

        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $served = $this->getServedQuestionContent($submissionId);

        foreach ($rows as &$row) {
            $row['order_index'] = $row['position'] !== null
                ? (int) $row['position']
                : (int) ($row['link_position'] ?? 0);

            $content = $served[(int) $row['question_id']] ?? null;
            if ($content !== null) {
                $row['question_text'] = $content['question_text'] ?? $row['question_text'];
                $row['correct_answer'] = $content['correct_answer'] ?? $row['correct_answer'];
                $row['explanation'] = $content['explanation'] ?? $row['explanation'];
                $row['question_type'] = $content['question_type'] ?? $row['question_type'];
                $row['points'] = $content['points'] ?? $row['points'];
            }

            unset($row['position'], $row['question_snapshot'], $row['link_position']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Get student's attempts for a quiz
     */
    public function getStudentAttempts(int $quizId, int $studentId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM quiz_submissions
            WHERE quiz_id = :quiz_id AND student_id = :student_id
            ORDER BY attempt DESC
        ");

        $stmt->execute([
            'quiz_id' => $quizId,
            'student_id' => $studentId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get quiz result summary for teachers/admins
     */
    public function getQuizResultsSummary(int $quizId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_attempts,
                COUNT(DISTINCT qs.student_id) AS unique_students,
                SUM(CASE WHEN qs.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_attempts,
                SUM(CASE WHEN qs.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_attempts,
                COALESCE(AVG(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 END), 0) AS avg_percentage,
                COALESCE(MAX(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 END), 0) AS best_percentage,
                COALESCE(MIN(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 END), 0) AS lowest_percentage
            FROM quiz_submissions qs
            WHERE qs.quiz_id = :quiz_id
        ");

        $stmt->execute(['quiz_id' => $quizId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: [
            'total_attempts' => 0,
            'unique_students' => 0,
            'submitted_attempts' => 0,
            'in_progress_attempts' => 0,
            'avg_percentage' => 0,
            'best_percentage' => 0,
            'lowest_percentage' => 0,
        ];
    }

    /**
     * Get quiz submissions for teachers/admins
     */
    public function getQuizSubmissions(int $quizId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                qs.submission_id,
                qs.quiz_id,
                qs.student_id,
                qs.attempt,
                qs.status,
                qs.score,
                qs.max_score,
                qs.created_at AS started_at,
                qs.submitted_at,
                qs.graded_at,
                s.student_id_number,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS student_name
            FROM quiz_submissions qs
            LEFT JOIN students s ON s.student_id = qs.student_id
            LEFT JOIN users u ON u.user_id = s.user_id
            WHERE qs.quiz_id = :quiz_id
            ORDER BY qs.submitted_at DESC, qs.created_at DESC, qs.attempt DESC
        ");

        $stmt->execute(['quiz_id' => $quizId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Parent dashboard: per-subject quiz score summary for a student
     */
    public function getStudentQuizPerformanceBySubject(int $studentId): array
    {
        $stmt = $this->db->prepare("\n            SELECT\n                COALESCE(s.subject_name, CONCAT('Course ', q.course_id)) AS subject_name,\n                COUNT(*) AS submitted_attempts,\n                ROUND(AVG(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 END), 1) AS avg_percentage,\n                ROUND(MAX(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 END), 1) AS best_percentage\n            FROM quiz_submissions qs\n            INNER JOIN quizzes q ON q.quiz_id = qs.quiz_id\n            LEFT JOIN class_subjects cs ON cs.course_id = q.course_id\n            LEFT JOIN subjects s ON s.subject_id = cs.subject_id\n            WHERE qs.student_id = :student_id\n              AND qs.status = 'submitted'\n            GROUP BY COALESCE(s.subject_name, CONCAT('Course ', q.course_id))\n            ORDER BY avg_percentage DESC, subject_name ASC\n        ");

        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Parent dashboard: quiz completion status summary for a student
     */
    public function getStudentQuizCompletionStatus(int $studentId): array
    {
        $stmt = $this->db->prepare("\n            SELECT\n                COUNT(DISTINCT q.quiz_id) AS assigned_quizzes,\n                COUNT(DISTINCT CASE WHEN subm.status = 'submitted' THEN q.quiz_id END) AS completed_quizzes,\n                COUNT(DISTINCT CASE WHEN subm.status = 'in_progress' THEN q.quiz_id END) AS in_progress_quizzes,\n                COUNT(DISTINCT subm.submission_id) AS total_attempts\n            FROM students st\n            INNER JOIN class_subjects cs\n                ON cs.class_id = st.class_id\n               AND LOWER(COALESCE(cs.status, '')) = 'active'\n            INNER JOIN quizzes q ON q.course_id = cs.course_id\n            LEFT JOIN quiz_submissions subm\n                ON subm.quiz_id = q.quiz_id\n               AND subm.student_id = st.student_id\n            WHERE st.student_id = :student_id\n              AND LOWER(COALESCE(q.status, '')) = 'active'\n        ");

        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $assigned = (int) ($row['assigned_quizzes'] ?? 0);
        $completed = (int) ($row['completed_quizzes'] ?? 0);
        $inProgress = (int) ($row['in_progress_quizzes'] ?? 0);
        $attempts = (int) ($row['total_attempts'] ?? 0);
        $completionRate = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0.0;

        return [
            'assigned_quizzes' => $assigned,
            'completed_quizzes' => $completed,
            'in_progress_quizzes' => $inProgress,
            'total_attempts' => $attempts,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * Parent dashboard: recent quiz attempts for a student
     */
    public function getRecentStudentQuizAttempts(int $studentId, int $limit = 6): array
    {
        $stmt = $this->db->prepare("\n            SELECT\n                qs.submission_id,\n                qs.quiz_id,\n                q.title AS quiz_title,\n                COALESCE(s.subject_name, CONCAT('Course ', q.course_id)) AS subject_name,\n                qs.attempt,\n                qs.status,\n                qs.score,\n                qs.max_score,\n                ROUND(CASE WHEN qs.max_score > 0 THEN (qs.score / qs.max_score) * 100 ELSE NULL END, 1) AS percentage,\n                qs.submitted_at,\n                qs.created_at\n            FROM quiz_submissions qs\n            INNER JOIN quizzes q ON q.quiz_id = qs.quiz_id\n            LEFT JOIN class_subjects cs ON cs.course_id = q.course_id\n            LEFT JOIN subjects s ON s.subject_id = cs.subject_id\n            WHERE qs.student_id = :student_id\n            ORDER BY COALESCE(qs.submitted_at, qs.created_at) DESC, qs.submission_id DESC\n            LIMIT :limit\n        ");

        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}