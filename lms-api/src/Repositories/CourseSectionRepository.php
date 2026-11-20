<?php

namespace App\Repositories;

use PDO;
use App\Config\Database;

class CourseSectionRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all sections for a course
     */
    public function getCourseSections(int $courseId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cs.*,
                CONCAT(u.first_name, ' ', u.last_name) as creator_name
            FROM course_sections cs
            LEFT JOIN users u ON cs.created_by = u.user_id
            WHERE cs.course_id = :course_id
            ORDER BY cs.order_index ASC, cs.created_at ASC
        ");

        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find section by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cs.*,
                c.institution_id,
                CONCAT(u.first_name, ' ', u.last_name) as creator_name
            FROM course_sections cs
            INNER JOIN class_subjects c ON cs.course_id = c.course_id
            LEFT JOIN users u ON cs.created_by = u.user_id
            WHERE cs.course_sections_id = :id
        ");

        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find a lesson-plan bucket that the question-bank owner may use.
     *
     * QuestionBankRepository scopes questions by the teacher account's
     * user_id, while course ownership is stored as teachers.teacher_id. Resolve
     * that mapping here so a bank question cannot reference another teacher's
     * course section.
     */
    public function findOwnedForQuestionBank(
        int $sectionId,
        int $institutionId,
        int $ownerUserId
    ): ?array {
        if ($sectionId <= 0 || $institutionId <= 0 || $ownerUserId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT cs.*, c.institution_id, c.teacher_id
            FROM course_sections cs
            INNER JOIN class_subjects c ON cs.course_id = c.course_id
            INNER JOIN teachers t ON c.teacher_id = t.teacher_id
            WHERE cs.course_sections_id = :section_id
              AND c.institution_id = :institution_id
              AND t.user_id = :owner_user_id
            LIMIT 1
        ");
        $stmt->execute([
            'section_id' => $sectionId,
            'institution_id' => $institutionId,
            'owner_user_id' => $ownerUserId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Create a new section
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO course_sections (
                course_id,
                section_name,
                description,
                order_index,
                is_active,
                created_by
            ) VALUES (
                :course_id,
                :section_name,
                :description,
                :order_index,
                :is_active,
                :created_by
            )
        ");

        $stmt->execute([
            'course_id' => $data['course_id'],
            'section_name' => $data['section_name'],
            'description' => $data['description'] ?? null,
            'order_index' => $data['order_index'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
            'created_by' => $data['created_by'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a section
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['section_name', 'description', 'order_index', 'is_active'];

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

        $sql = "UPDATE course_sections SET " . implode(', ', $fields) . " WHERE course_sections_id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete a section
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM course_sections WHERE course_sections_id = :id
        ");

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Count sections for a course
     */
    public function countCourseSections(int $courseId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM course_sections
            WHERE course_id = :course_id
        ");

        $stmt->execute(['course_id' => $courseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $result['count'];
    }
}
