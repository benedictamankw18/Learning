<?php

namespace App\Repositories;

use App\Config\Database;
use App\Security\AccessControl;
use App\Utils\QuestionVersion;
use PDO;

/**
 * Teacher-private question bank taxonomy and immutable versions.
 *
 * Quiz authoring still goes through QuizRepository; this repository owns the
 * bank-wide operations and the immutable version rows used by attempts. Every
 * question lookup is scoped to the authenticated teacher's own rows.
 */
class QuestionBankRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * SQL fragment and parameters limiting questions to the actor's own bank.
     *
     * There is deliberately no role or institution sharing branch: a bank is
     * private to one teacher, and ownership cannot be reassigned through any
     * API. The institution check only denies actors without tenant scope.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public function ownerClause(array $actor, string $alias = 'q'): array
    {
        $institutionId = AccessControl::institutionId($actor);
        if ($institutionId <= 0) {
            return ['1=0', []];
        }

        $prefix = $alias !== '' ? $alias . '.' : '';

        return [
            "{$prefix}owner_user_id = :owner_user_id AND {$prefix}institution_id = :institution_id",
            [
                'owner_user_id' => (int) ($actor['user_id'] ?? 0),
                'institution_id' => $institutionId,
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Sections (categories)                                               */
    /* ------------------------------------------------------------------ */

    public function listCategories(int $institutionId, int $ownerId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS parent_name
            FROM question_categories c
            LEFT JOIN question_categories p
                ON p.category_id = c.parent_id
                AND p.institution_id = c.institution_id
                AND p.owner_user_id = c.owner_user_id
            WHERE c.institution_id = :institution_id AND c.owner_user_id = :owner_user_id
            ORDER BY c.parent_id ASC, c.name ASC
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCategory(int $categoryId, int $institutionId, int $ownerId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM question_categories
            WHERE category_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $categoryId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createCategory(int $institutionId, int $ownerId, string $name, ?int $parentId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO question_categories (institution_id, owner_user_id, name, parent_id)
            VALUES (:institution_id, :owner_user_id, :name, :parent_id)
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'name' => trim($name),
            'parent_id' => $parentId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateCategory(
        int $categoryId,
        int $institutionId,
        int $ownerId,
        string $name,
        ?int $parentId
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE question_categories
            SET name = :name, parent_id = :parent_id
            WHERE category_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'name' => trim($name),
            'parent_id' => $parentId,
            'id' => $categoryId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteCategory(int $categoryId, int $institutionId, int $ownerId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM question_categories
            WHERE category_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $categoryId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function categoryInUse(int $categoryId, int $institutionId, int $ownerId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM questions
            WHERE category_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $categoryId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Topics                                                              */
    /* ------------------------------------------------------------------ */

    public function listTopics(int $institutionId, ?int $subjectId, int $ownerId): array
    {
        $sql = "
            SELECT t.*, s.subject_name
            FROM question_topics t
            LEFT JOIN subjects s ON s.subject_id = t.subject_id
            WHERE t.institution_id = :institution_id AND t.owner_user_id = :owner_user_id
        ";
        $params = [
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ];

        if ($subjectId !== null) {
            $sql .= " AND (t.subject_id = :subject_id OR t.subject_id IS NULL)";
            $params['subject_id'] = $subjectId;
        }

        $sql .= " ORDER BY t.subject_id ASC, t.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTopic(int $topicId, int $institutionId, int $ownerId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM question_topics
            WHERE topic_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $topicId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createTopic(int $institutionId, int $ownerId, ?int $subjectId, string $name): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO question_topics (institution_id, owner_user_id, subject_id, name)
            VALUES (:institution_id, :owner_user_id, :subject_id, :name)
        ");
        $stmt->execute([
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
            'subject_id' => $subjectId,
            'name' => trim($name),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateTopic(
        int $topicId,
        int $institutionId,
        int $ownerId,
        ?int $subjectId,
        string $name
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE question_topics
            SET subject_id = :subject_id, name = :name
            WHERE topic_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'subject_id' => $subjectId,
            'name' => trim($name),
            'id' => $topicId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteTopic(int $topicId, int $institutionId, int $ownerId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM question_topics
            WHERE topic_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $topicId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function topicInUse(int $topicId, int $institutionId, int $ownerId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM questions
            WHERE topic_id = :id
              AND institution_id = :institution_id
              AND owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'id' => $topicId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerId,
        ]);

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
        [$ownerClause, $params] = $this->ownerClause($actor);

        $sql = "
            SELECT q.*, c.name AS category_name, t.name AS topic_name,
                   s.subject_name, u.username AS owner_username,
                   cs.section_name AS course_section_name,
                   qsub.subject_name AS course_section_subject_name,
                   qclass.class_name AS course_section_class_name,
                   (SELECT COUNT(*) FROM question_versions v WHERE v.question_id = q.question_id) AS version_count
            FROM questions q
            LEFT JOIN question_categories c ON c.category_id = q.category_id
            LEFT JOIN question_topics t ON t.topic_id = q.topic_id
            LEFT JOIN subjects s ON s.subject_id = q.subject_id
            LEFT JOIN users u ON u.user_id = q.owner_user_id
            LEFT JOIN course_sections cs ON cs.course_sections_id = q.course_section_id
            LEFT JOIN class_subjects qcourse ON qcourse.course_id = cs.course_id
            LEFT JOIN subjects qsub ON qsub.subject_id = qcourse.subject_id
            LEFT JOIN classes qclass ON qclass.class_id = qcourse.class_id
            WHERE {$ownerClause}
        ";

        foreach (['category_id', 'subject_id', 'topic_id', 'course_section_id'] as $field) {
            if (isset($filters[$field]) && (int) $filters[$field] > 0) {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }
        if (!empty($filters['include_archived'])) {
            // The teacher opted to see archived rows as well.
        } elseif (isset($filters['is_archived'])) {
            $sql .= " AND q.is_archived = :is_archived";
            $params['is_archived'] = (int) ((bool) $filters['is_archived']);
        } else {
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
        [$ownerClause, $params] = $this->ownerClause($actor);
        $sql = "SELECT COUNT(*) FROM questions q WHERE {$ownerClause}";

        foreach (['category_id', 'subject_id', 'topic_id', 'course_section_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if (!empty($filters['include_archived'])) {
            // The teacher opted to see archived rows as well.
        } elseif (isset($filters['is_archived'])) {
            $sql .= " AND q.is_archived = :is_archived";
            $params['is_archived'] = (int) ((bool) $filters['is_archived']);
        } else {
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

    public function findOwnedQuestion(int $questionId, array $actor): ?array
    {
        [$ownerClause, $params] = $this->ownerClause($actor);
        $params['question_id'] = $questionId;

        $stmt = $this->db->prepare("
            SELECT q.*, c.name AS category_name, t.name AS topic_name, s.subject_name,
                   cs.section_name AS course_section_name,
                   qsub.subject_name AS course_section_subject_name,
                   qclass.class_name AS course_section_class_name
            FROM questions q
            LEFT JOIN question_categories c ON c.category_id = q.category_id
            LEFT JOIN question_topics t ON t.topic_id = q.topic_id
            LEFT JOIN subjects s ON s.subject_id = q.subject_id
            LEFT JOIN course_sections cs ON cs.course_sections_id = q.course_section_id
            LEFT JOIN class_subjects qcourse ON qcourse.course_id = cs.course_id
            LEFT JOIN subjects qsub ON qsub.subject_id = qcourse.subject_id
            LEFT JOIN classes qclass ON qclass.class_id = qcourse.class_id
            WHERE {$ownerClause} AND q.question_id = :question_id
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // The editor loads current options from the owned question, not from
        // immutable versions. Versions remain for attempt snapshots only.
        $optionStmt = $this->db->prepare("
            SELECT option_label, option_text, is_correct
            FROM question_bank_options
            WHERE question_id = :question_id
            ORDER BY option_label ASC
        ");
        $optionStmt->execute(['question_id' => $questionId]);
        $row['options'] = $optionStmt->fetchAll(PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateMetadata(int $questionId, array $data): bool
    {
        $fields = [];
        $params = ['question_id' => $questionId];

        foreach (['category_id', 'subject_id', 'topic_id', 'course_section_id'] as $field) {
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

    public function setArchived(int $questionId, bool $archived, array $actor): bool
    {
        [$ownerClause, $params] = $this->ownerClause($actor, '');
        $params['question_id'] = $questionId;
        $params['archived'] = $archived ? 1 : 0;

        $stmt = $this->db->prepare("
            UPDATE questions SET is_archived = :archived, updated_at = NOW()
            WHERE {$ownerClause} AND question_id = :question_id
        ");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rows for CSV export, including options and only the teacher's own rows.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function questionsForExport(array $filters, array $actor): array
    {
        [$ownerClause, $params] = $this->ownerClause($actor);

        $sql = "SELECT q.* FROM questions q WHERE {$ownerClause}";

        foreach (['category_id', 'subject_id', 'topic_id', 'course_section_id'] as $field) {
            if (isset($filters[$field]) && (int) $filters[$field] > 0) {
                $sql .= " AND q.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }
        if (!empty($filters['include_archived'])) {
            // The teacher opted to see archived rows as well.
        } elseif (isset($filters['is_archived'])) {
            $sql .= " AND q.is_archived = :is_archived";
            $params['is_archived'] = (int) ((bool) $filters['is_archived']);
        } else {
            $sql .= " AND q.is_archived = 0";
        }
        if (!empty($filters['q'])) {
            $sql .= " AND q.question_text LIKE :search";
            $params['search'] = '%' . $this->escapeLike((string) $filters['q']) . '%';
        }

        $sql .= " ORDER BY q.question_id ASC";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $optionStmt = $this->db->prepare("
            SELECT option_label, option_text, is_correct
            FROM question_bank_options
            WHERE question_id = :id
            ORDER BY option_label ASC
        ");
        foreach ($questions as $index => $question) {
            $optionStmt->execute(['id' => (int) $question['question_id']]);
            $questions[$index]['options'] = $optionStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $questions;
    }

    /**
     * Append prepared CSV rows to the teacher's private bank.
     *
     * Duplicate text in the teacher's own bank is skipped. Every created row
     * belongs to the actor and gets its first immutable version through
     * createQuestion(), exactly like a manually authored bank question.
     *
     * @param array<int,array{row:int,question:array<string,mixed>}> $prepared
     * @return array{imported:int,skipped:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public function importQuestions(array $prepared, array $actor, int $institutionId): array
    {
        [$ownerClause, $ownerParams] = $this->ownerClause($actor);

        $findExisting = $this->db->prepare("
            SELECT question_id
            FROM questions q
            WHERE {$ownerClause} AND TRIM(q.question_text) = :question_text
            ORDER BY q.question_id ASC
            LIMIT 1
        ");

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        try {
            foreach ($prepared as $index => $row) {
                $rowNumber = (int) ($row['row'] ?? 0);
                $question = is_array($row['question'] ?? null) ? $row['question'] : [];
                $text = (string) ($question['question_text'] ?? '');

                if ($text === '') {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'message' => 'question_text is required.',
                    ];
                    continue;
                }

                $lookupParams = $ownerParams;
                $lookupParams['question_text'] = $text;
                $findExisting->execute($lookupParams);
                if ($findExisting->fetchColumn() !== false) {
                    $skipped++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'skipped',
                        'message' => 'A question with this text already exists in your question bank.',
                    ];
                    continue;
                }

                $savepoint = 'qb_import_' . (int) $index;
                $this->db->exec('SAVEPOINT ' . $savepoint);
                try {
                    $this->createQuestion($question, $actor, $institutionId);
                    $imported++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'imported',
                        'message' => 'Question imported into your private bank.',
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
     * Create a question in the authenticated teacher's private bank.
     *
     * @param array<string,mixed> $data
     */
    public function createQuestion(array $data, array $actor, int $institutionId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO questions (
                institution_id, owner_user_id, question_text, image_question,
                question_type, points, difficulty, explanation, correct_answer,
                is_archived, category_id, subject_id, topic_id, course_section_id
            ) VALUES (
                :institution_id, :owner_user_id, :question_text, :image_question,
                :question_type, :points, :difficulty, :explanation, :correct_answer,
                0, :category_id, :subject_id, :topic_id, :course_section_id
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
            'course_section_id' => $this->nullableInt($data['course_section_id'] ?? null),
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
