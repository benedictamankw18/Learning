<?php

namespace App\Repositories;

use App\Config\Database;
use App\Security\AccessControl;
use App\Utils\QuestionVersion;
use PDO;

/**
 * Phase 1C question bank metadata, taxonomy, approval and versions.
 *
 * Quiz authoring still goes through QuizRepository; this repository owns the
 * bank-wide operations and the immutable version rows used by attempts.
 */
class QuestionBankRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * SQL fragment and parameters limiting questions visible to an actor.
     *
     * Teachers see their own questions and approved institution questions.
     * Admins see every question in their institution; super admins see all.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public function visibilityClause(array $actor, string $alias = 'q'): array
    {
        if (AccessControl::isSuperAdmin($actor)) {
            return ['1=1', []];
        }

        $institutionId = AccessControl::institutionId($actor);
        if ($institutionId <= 0) {
            return ['1=0', []];
        }

        if (AccessControl::isAdmin($actor)) {
            return ["{$alias}.institution_id = :institution_id", ['institution_id' => $institutionId]];
        }

        return [
            "{$alias}.institution_id = :institution_id
             AND (
                {$alias}.owner_user_id = :owner_user_id
                OR ({$alias}.visibility = 'institution'
                    AND {$alias}.approval_status = 'approved'
                    AND {$alias}.is_archived = 0)
             )",
            [
                'institution_id' => $institutionId,
                'owner_user_id' => (int) ($actor['user_id'] ?? 0),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    public function listCategories(int $institutionId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS parent_name
            FROM question_categories c
            LEFT JOIN question_categories p ON p.category_id = c.parent_id
            WHERE c.institution_id = :institution_id
            ORDER BY c.parent_id ASC, c.name ASC
        ");
        $stmt->execute(['institution_id' => $institutionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCategory(int $categoryId, int $institutionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM question_categories
            WHERE category_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $categoryId, 'institution_id' => $institutionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createCategory(int $institutionId, string $name, ?int $parentId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO question_categories (institution_id, name, parent_id)
            VALUES (:institution_id, :name, :parent_id)
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'name' => trim($name),
            'parent_id' => $parentId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateCategory(int $categoryId, int $institutionId, string $name, ?int $parentId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE question_categories
            SET name = :name, parent_id = :parent_id
            WHERE category_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute([
            'name' => trim($name),
            'parent_id' => $parentId,
            'id' => $categoryId,
            'institution_id' => $institutionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteCategory(int $categoryId, int $institutionId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM question_categories
            WHERE category_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $categoryId, 'institution_id' => $institutionId]);

        return $stmt->rowCount() > 0;
    }

    public function categoryInUse(int $categoryId, int $institutionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM questions
            WHERE category_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $categoryId, 'institution_id' => $institutionId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Topics                                                              */
    /* ------------------------------------------------------------------ */

    public function listTopics(int $institutionId, ?int $subjectId): array
    {
        $sql = "
            SELECT t.*, s.subject_name
            FROM question_topics t
            LEFT JOIN subjects s ON s.subject_id = t.subject_id
            WHERE t.institution_id = :institution_id
        ";
        $params = ['institution_id' => $institutionId];

        if ($subjectId !== null) {
            $sql .= " AND (t.subject_id = :subject_id OR t.subject_id IS NULL)";
            $params['subject_id'] = $subjectId;
        }

        $sql .= " ORDER BY t.subject_id ASC, t.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTopic(int $topicId, int $institutionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM question_topics
            WHERE topic_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $topicId, 'institution_id' => $institutionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createTopic(int $institutionId, ?int $subjectId, string $name): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO question_topics (institution_id, subject_id, name)
            VALUES (:institution_id, :subject_id, :name)
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'subject_id' => $subjectId,
            'name' => trim($name),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateTopic(int $topicId, int $institutionId, ?int $subjectId, string $name): bool
    {
        $stmt = $this->db->prepare("
            UPDATE question_topics
            SET subject_id = :subject_id, name = :name
            WHERE topic_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute([
            'subject_id' => $subjectId,
            'name' => trim($name),
            'id' => $topicId,
            'institution_id' => $institutionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteTopic(int $topicId, int $institutionId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM question_topics
            WHERE topic_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $topicId, 'institution_id' => $institutionId]);

        return $stmt->rowCount() > 0;
    }

    public function topicInUse(int $topicId, int $institutionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM questions
            WHERE topic_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $topicId, 'institution_id' => $institutionId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function subjectExists(int $subjectId, int $institutionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM subjects
            WHERE subject_id = :id AND institution_id = :institution_id
        ");
        $stmt->execute(['id' => $subjectId, 'institution_id' => $institutionId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Questions                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listQuestions(array $filters, array $actor, int $limit, int $offset): array
    {
        [$visibility, $params] = $this->visibilityClause($actor);

        $sql = "
            SELECT q.*, c.name AS category_name, t.name AS topic_name,
                   s.subject_name, u.username AS owner_username,
                   (SELECT COUNT(*) FROM question_versions v WHERE v.question_id = q.question_id) AS version_count
            FROM questions q
            LEFT JOIN question_categories c ON c.category_id = q.category_id
            LEFT JOIN question_topics t ON t.topic_id = q.topic_id
            LEFT JOIN subjects s ON s.subject_id = q.subject_id
            LEFT JOIN users u ON u.user_id = q.owner_user_id
            WHERE {$visibility}
        ";

        foreach (['category_id', 'subject_id', 'topic_id'] as $field) {
            if (isset($filters[$field]) && (int) $filters[$field] > 0) {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }
        foreach (['visibility', 'approval_status'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = (string) $filters[$field];
            }
        }
        if (isset($filters['is_archived'])) {
            $sql .= " AND q.is_archived = :is_archived";
            $params['is_archived'] = (int) ((bool) $filters['is_archived']);
        } elseif (!AccessControl::isAdmin($actor) && !AccessControl::isSuperAdmin($actor)) {
            $sql .= " AND q.is_archived = 0";
        }
        if (!empty($filters['q'])) {
            $sql .= " AND q.question_text LIKE :search";
            $params['search'] = '%' . $this->escapeLike((string) $filters['q']) . '%';
        }

        $sql .= " ORDER BY q.updated_at DESC, q.question_id DESC LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function countQuestions(array $filters, array $actor): int
    {
        [$visibility, $params] = $this->visibilityClause($actor);
        $sql = "SELECT COUNT(*) FROM questions q WHERE {$visibility}";

        foreach (['category_id', 'subject_id', 'topic_id', 'visibility', 'approval_status'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if (isset($filters['is_archived'])) {
            $sql .= " AND q.is_archived = :is_archived";
            $params['is_archived'] = (int) ((bool) $filters['is_archived']);
        } elseif (!AccessControl::isAdmin($actor) && !AccessControl::isSuperAdmin($actor)) {
            $sql .= " AND q.is_archived = 0";
        }
        if (!empty($filters['q'])) {
            $sql .= " AND q.question_text LIKE :search";
            $params['search'] = '%' . $this->escapeLike((string) $filters['q']) . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findVisibleQuestion(int $questionId, array $actor): ?array
    {
        [$visibility, $params] = $this->visibilityClause($actor);
        $params['question_id'] = $questionId;

        $stmt = $this->db->prepare("
            SELECT q.*, c.name AS category_name, t.name AS topic_name, s.subject_name
            FROM questions q
            LEFT JOIN question_categories c ON c.category_id = q.category_id
            LEFT JOIN question_topics t ON t.topic_id = q.topic_id
            LEFT JOIN subjects s ON s.subject_id = q.subject_id
            WHERE {$visibility} AND q.question_id = :question_id
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateMetadata(int $questionId, array $data): bool
    {
        $fields = [];
        $params = ['question_id' => $questionId];

        foreach (['category_id', 'subject_id', 'topic_id', 'visibility'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field] === null || $data[$field] === '' ? null : (int) $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare(
            'UPDATE questions SET ' . implode(', ', $fields) . ' WHERE question_id = :question_id'
        );

        return $stmt->execute($params);
    }

    public function submitForApproval(int $questionId, int $actorId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE questions
            SET approval_status = 'pending', visibility = 'private',
                submitted_at = NOW(), updated_at = NOW()
            WHERE question_id = :question_id
        ");
        $stmt->execute(['question_id' => $questionId]);

        return $stmt->rowCount() > 0;
    }

    public function approve(int $questionId, int $reviewerId, ?string $note): bool
    {
        $stmt = $this->db->prepare("
            UPDATE questions
            SET visibility = 'institution', approval_status = 'approved',
                reviewed_by = :reviewer_id, reviewed_at = NOW(), review_note = :note,
                updated_at = NOW()
            WHERE question_id = :question_id
        ");
        $stmt->execute([
            'question_id' => $questionId,
            'reviewer_id' => $reviewerId,
            'note' => $note,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function reject(int $questionId, int $reviewerId, string $note): bool
    {
        $stmt = $this->db->prepare("
            UPDATE questions
            SET visibility = 'private', approval_status = 'rejected',
                reviewed_by = :reviewer_id, reviewed_at = NOW(), review_note = :note,
                updated_at = NOW()
            WHERE question_id = :question_id
        ");
        $stmt->execute([
            'question_id' => $questionId,
            'reviewer_id' => $reviewerId,
            'note' => $note,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function setArchived(int $questionId, bool $archived): bool
    {
        $stmt = $this->db->prepare("
            UPDATE questions SET is_archived = :archived, updated_at = NOW()
            WHERE question_id = :question_id
        ");
        $stmt->execute([
            'question_id' => $questionId,
            'archived' => $archived ? 1 : 0,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Copy an institution question into the actor's private bank.
     *
     * Ownership and versions are new; the original row is never changed.
     */
    public function fork(int $questionId, array $actor): ?int
    {
        $source = $this->db->prepare("SELECT * FROM questions WHERE question_id = :id");
        $source->execute(['id' => $questionId]);
        $question = $source->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            return null;
        }

        $options = $this->db->prepare("
            SELECT option_label, option_text, is_correct
            FROM question_bank_options
            WHERE question_id = :id
            ORDER BY option_label ASC
        ");
        $options->execute(['id' => $questionId]);

        $insert = $this->db->prepare("
            INSERT INTO questions (
                institution_id, owner_user_id, question_text, image_question,
                question_type, points, difficulty, explanation, correct_answer,
                is_archived, category_id, subject_id, topic_id, visibility,
                approval_status, forked_from_id
            ) VALUES (
                :institution_id, :owner_user_id, :question_text, :image_question,
                :question_type, :points, :difficulty, :explanation, :correct_answer,
                0, :category_id, :subject_id, :topic_id, 'private', 'draft', :forked_from_id
            )
        ");
        $insert->execute([
            'institution_id' => (int) $question['institution_id'],
            'owner_user_id' => (int) ($actor['user_id'] ?? 0),
            'question_text' => $question['question_text'],
            'image_question' => $question['image_question'],
            'question_type' => $question['question_type'],
            'points' => $question['points'],
            'difficulty' => $question['difficulty'],
            'explanation' => $question['explanation'],
            'correct_answer' => $question['correct_answer'],
            'category_id' => $question['category_id'],
            'subject_id' => $question['subject_id'],
            'topic_id' => $question['topic_id'],
            'forked_from_id' => $questionId,
        ]);
        $newId = (int) $this->db->lastInsertId();

        $insertOption = $this->db->prepare("
            INSERT INTO question_bank_options (question_id, option_label, option_text, is_correct)
            VALUES (:question_id, :option_label, :option_text, :is_correct)
        ");
        foreach ($options->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $insertOption->execute([
                'question_id' => $newId,
                'option_label' => $option['option_label'],
                'option_text' => $option['option_text'],
                'is_correct' => $option['is_correct'],
            ]);
        }

        $this->createVersion($newId, (int) ($actor['user_id'] ?? 0));

        return $newId;
    }

    /**
     * Create a private question directly in the bank.
     *
     * @param array<string,mixed> $data
     */
    public function createQuestion(array $data, array $actor, int $institutionId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO questions (
                institution_id, owner_user_id, question_text, image_question,
                question_type, points, difficulty, explanation, correct_answer,
                is_archived, category_id, subject_id, topic_id, visibility, approval_status
            ) VALUES (
                :institution_id, :owner_user_id, :question_text, :image_question,
                :question_type, :points, :difficulty, :explanation, :correct_answer,
                0, :category_id, :subject_id, :topic_id, 'private', 'draft'
            )
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'owner_user_id' => (int) ($actor['user_id'] ?? 0),
            'question_text' => trim((string) ($data['question_text'] ?? '')),
            'image_question' => $data['image_question'] ?? null,
            'question_type' => (string) ($data['question_type'] ?? 'multiple_choice'),
            'points' => (int) ($data['points'] ?? 1),
            'difficulty' => (string) ($data['difficulty'] ?? 'medium'),
            'explanation' => $data['explanation'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'category_id' => $this->nullableInt($data['category_id'] ?? null),
            'subject_id' => $this->nullableInt($data['subject_id'] ?? null),
            'topic_id' => $this->nullableInt($data['topic_id'] ?? null),
        ]);
        $questionId = (int) $this->db->lastInsertId();

        foreach (($data['options'] ?? []) as $option) {
            if (!is_array($option) || empty($option['label']) || empty($option['text'])) {
                continue;
            }
            $optionStmt = $this->db->prepare("
                INSERT INTO question_bank_options (question_id, option_label, option_text, is_correct)
                VALUES (:question_id, :option_label, :option_text, :is_correct)
            ");
            $optionStmt->execute([
                'question_id' => $questionId,
                'option_label' => strtoupper(trim((string) $option['label'])),
                'option_text' => trim((string) $option['text']),
                'is_correct' => !empty($option['is_correct']) ? 1 : 0,
            ]);
        }

        $this->createVersion($questionId, (int) ($actor['user_id'] ?? 0));

        return $questionId;
    }

    /**
     * Update the answerable content of a bank question.
     *
     * @param array<string,mixed> $data
     */
    public function updateContent(int $questionId, array $data): bool
    {
        $fields = [];
        $params = ['question_id' => $questionId];

        foreach ([
            'question_text', 'image_question', 'question_type', 'points',
            'difficulty', 'explanation', 'correct_answer',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare(
            'UPDATE questions SET ' . implode(', ', $fields) . ' WHERE question_id = :question_id'
        );
        $stmt->execute($params);

        if (array_key_exists('options', $data) && is_array($data['options'])) {
            $this->db->prepare('DELETE FROM question_bank_options WHERE question_id = :id')
                ->execute(['id' => $questionId]);

            $insert = $this->db->prepare("
                INSERT INTO question_bank_options (question_id, option_label, option_text, is_correct)
                VALUES (:question_id, :option_label, :option_text, :is_correct)
            ");
            foreach ($data['options'] as $option) {
                if (!is_array($option) || empty($option['label']) || empty($option['text'])) {
                    continue;
                }
                $insert->execute([
                    'question_id' => $questionId,
                    'option_label' => strtoupper(trim((string) $option['label'])),
                    'option_text' => trim((string) $option['text']),
                    'is_correct' => !empty($option['is_correct']) ? 1 : 0,
                ]);
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Versions                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Freeze the current bank content as a new immutable version.
     */
    public function createVersion(int $questionId, ?int $actorId): int
    {
        $questionStmt = $this->db->prepare("SELECT * FROM questions WHERE question_id = :id");
        $questionStmt->execute(['id' => $questionId]);
        $question = $questionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            throw new \RuntimeException('Question not found');
        }

        $optionStmt = $this->db->prepare("
            SELECT option_label, option_text, is_correct
            FROM question_bank_options
            WHERE question_id = :id
            ORDER BY option_label ASC
        ");
        $optionStmt->execute(['id' => $questionId]);

        $content = QuestionVersion::normalize([
            'question_id' => $questionId,
            'question_text' => $question['question_text'],
            'image_question' => $question['image_question'],
            'question_type' => $question['question_type'],
            'points' => $question['points'],
            'difficulty' => $question['difficulty'],
            'explanation' => $question['explanation'],
            'correct_answer' => $question['correct_answer'],
            'options' => $optionStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);

        $nextStmt = $this->db->prepare("
            SELECT COALESCE(MAX(version_number), 0) + 1
            FROM question_versions
            WHERE question_id = :id
        ");
        $nextStmt->execute(['id' => $questionId]);
        $versionNumber = (int) $nextStmt->fetchColumn();

        $insert = $this->db->prepare("
            INSERT INTO question_versions (
                question_id, institution_id, version_number, content_json, created_by
            ) VALUES (
                :question_id, :institution_id, :version_number, :content_json, :created_by
            )
        ");
        $insert->execute([
            'question_id' => $questionId,
            'institution_id' => (int) $question['institution_id'],
            'version_number' => $versionNumber,
            'content_json' => QuestionVersion::encode($content),
            'created_by' => $actorId,
        ]);
        $versionId = (int) $this->db->lastInsertId();

        $update = $this->db->prepare("
            UPDATE questions SET current_version_id = :version_id WHERE question_id = :question_id
        ");
        $update->execute(['version_id' => $versionId, 'question_id' => $questionId]);

        return $versionId;
    }

    public function listVersions(int $questionId): array
    {
        $stmt = $this->db->prepare("
            SELECT v.version_id, v.version_number, v.created_at, v.created_by,
                   u.username AS created_by_username, v.content_json
            FROM question_versions v
            LEFT JOIN users u ON u.user_id = v.created_by
            WHERE v.question_id = :question_id
            ORDER BY v.version_number DESC
        ");
        $stmt->execute(['question_id' => $questionId]);

        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($versions as &$version) {
            $version['content'] = QuestionVersion::decode($version['content_json'] ?? null);
            unset($version['content_json']);
        }
        unset($version);

        return $versions;
    }

    public function findVersionContent(int $versionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT content_json FROM question_versions WHERE version_id = :id
        ");
        $stmt->execute(['id' => $versionId]);

        return QuestionVersion::decode($stmt->fetchColumn() ?: null);
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
