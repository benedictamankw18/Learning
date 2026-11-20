<?php

namespace App\Controllers;

use App\Middleware\RoleMiddleware;
use App\Repositories\QuestionBankRepository;
use App\Security\AccessControl;
use App\Utils\QuestionCsv;
use App\Utils\Response;
use App\Utils\Validator;

/**
 * Phase 1C: bank-wide browsing, taxonomy, approval and version history.
 *
 * Quiz-scoped question editing stays in QuizController so existing clients
 * keep working; this controller adds the librarian-style operations.
 */
class QuestionBankController
{
    private QuestionBankRepository $repo;

    public function __construct()
    {
        $this->repo = new QuestionBankRepository();
    }

    private function readBody(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Institution used for new records. Super admins must supply one because
     * their account has no institution of its own.
     */
    private function institutionFor(array $user, array $body = []): int
    {
        if (AccessControl::isSuperAdmin($user)) {
            $candidate = $body['institution_id'] ?? $_POST['institution_id'] ?? $_GET['institution_id'] ?? null;

            return (int) $candidate;
        }

        return AccessControl::institutionId($user);
    }

    private function requireInstitution(array $user, array $body = []): int
    {
        $institutionId = $this->institutionFor($user, $body);
        if ($institutionId <= 0) {
            Response::forbidden('Institution scope is required');
        }

        return $institutionId;
    }

    private function requireBankRole(array $user): void
    {
        $middleware = new RoleMiddleware($user);
        if (!$middleware->requireRole(['teacher', 'admin', 'super_admin'])) {
            return;
        }
    }

    private function requireAdmin(array $user): void
    {
        $middleware = new RoleMiddleware($user);
        if (!$middleware->requireRole(['admin'])) {
            return;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Questions                                                           */
    /* ------------------------------------------------------------------ */

    public function index(array $user): void
    {
        $this->requireBankRole($user);

        $filters = [
            'category_id' => $_GET['category_id'] ?? null,
            'subject_id' => $_GET['subject_id'] ?? null,
            'topic_id' => $_GET['topic_id'] ?? null,
            'visibility' => $_GET['visibility'] ?? null,
            'approval_status' => $_GET['approval_status'] ?? null,
            'is_archived' => isset($_GET['is_archived']) ? (int) $_GET['is_archived'] : null,
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        foreach (['visibility', 'approval_status'] as $field) {
            if (!empty($filters[$field])) {
                $allowed = $field === 'visibility'
                    ? ['private', 'institution']
                    : ['draft', 'pending', 'approved', 'rejected'];
                if (!in_array($filters[$field], $allowed, true)) {
                    Response::validationError([$field => ['Invalid filter value']]);
                    return;
                }
            }
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $questions = $this->repo->listQuestions($filters, $user, $limit, $offset);
        $total = $this->repo->countQuestions($filters, $user);

        Response::success([
            'questions' => $questions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    public function show(array $user, int $id): void
    {
        $this->requireBankRole($user);

        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        Response::success($question);
    }

    public function create(array $user): void
    {
        $this->requireBankRole($user);
        $institutionId = $this->requireInstitution($user);
        $data = $this->readBody();

        $validator = new Validator($data);
        $validator->required(['question_text', 'question_type'])
            ->max('question_text', 65535)
            ->in('question_type', ['multiple_choice', 'true_false', 'short_answer', 'essay'])
            ->numeric('points')
            ->numeric('category_id')
            ->numeric('subject_id')
            ->numeric('topic_id')
            ->in('difficulty', ['easy', 'medium', 'hard'])
            ->max('image_question', 255)
            ->max('correct_answer', 500);

        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        if (!$this->metadataReferencesValid($data, $institutionId)) {
            Response::validationError(['metadata' => ['Category, subject or topic does not belong to this institution']]);
            return;
        }

        $options = $this->normalizeOptions($data['options'] ?? []);
        if (($data['question_type'] ?? '') === 'multiple_choice' && count($options) < 2) {
            Response::validationError(['options' => ['Multiple choice questions require at least two options']]);
            return;
        }

        $data['options'] = $options;
        $questionId = $this->repo->createQuestion($data, $user, $institutionId);

        Response::success(['question_id' => $questionId], 'Question created', 201);
    }

    public function update(array $user, int $id): void
    {
        $this->requireBankRole($user);

        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        $isAdmin = AccessControl::isSuperAdmin($user) || AccessControl::isAdmin($user);
        if (!$isAdmin && (int) $question['owner_user_id'] !== (int) ($user['user_id'] ?? 0)) {
            Response::forbidden('Only the creator or an administrator can edit this question');
            return;
        }

        $data = $this->readBody();
        $validator = new Validator($data);
        $validator->max('question_text', 65535)
            ->in('question_type', ['multiple_choice', 'true_false', 'short_answer', 'essay'])
            ->numeric('points')
            ->numeric('category_id')
            ->numeric('subject_id')
            ->numeric('topic_id')
            ->in('difficulty', ['easy', 'medium', 'hard'])
            ->in('visibility', ['private', 'institution'])
            ->max('image_question', 255)
            ->max('correct_answer', 500);

        // Only administrators may change sharing directly. Teachers use the
        // approval flow instead.
        if (!$isAdmin && array_key_exists('visibility', $data)) {
            Response::forbidden('Use submit-for-approval to share a question');
            return;
        }
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $institutionId = (int) $question['institution_id'];
        if (!$this->metadataReferencesValid($data, $institutionId)) {
            Response::validationError(['metadata' => ['Category, subject or topic does not belong to this institution']]);
            return;
        }

        $contentFields = [
            'question_text', 'image_question', 'question_type', 'points',
            'difficulty', 'explanation', 'correct_answer', 'options',
        ];
        $hasContentChange = false;
        foreach ($contentFields as $field) {
            if (array_key_exists($field, $data)) {
                $hasContentChange = true;
                break;
            }
        }

        $ownsTransaction = !\App\Config\Database::getInstance()->getConnection()->inTransaction();
        $db = \App\Config\Database::getInstance()->getConnection();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            if (array_key_exists('options', $data)) {
                $data['options'] = $this->normalizeOptions($data['options']);
            }
            if ($hasContentChange) {
                $this->repo->updateContent($id, $data);
            }

            $metadata = array_intersect_key($data, array_flip(['category_id', 'subject_id', 'topic_id', 'visibility']));
            if ($metadata !== []) {
                $this->repo->updateMetadata($id, $metadata);
            }

            if ($hasContentChange) {
                $this->repo->createVersion($id, (int) ($user['user_id'] ?? 0));

                // A teacher's edit to a shared question sends it back through
                // review; administrator edits stay approved.
                if (!$isAdmin && ($question['approval_status'] ?? '') === 'approved') {
                    $this->repo->submitForApproval($id, (int) ($user['user_id'] ?? 0));
                }
            }

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Response::success(['message' => 'Question updated successfully']);
    }

    public function submitForApproval(array $user, int $id): void
    {
        $this->requireBankRole($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        $isAdmin = AccessControl::isSuperAdmin($user) || AccessControl::isAdmin($user);
        if (!$isAdmin && (int) $question['owner_user_id'] !== (int) ($user['user_id'] ?? 0)) {
            Response::forbidden('Only the creator can submit this question');
            return;
        }
        if (($question['approval_status'] ?? '') === 'approved') {
            Response::validationError(['question' => ['This question is already approved']]);
            return;
        }

        $this->repo->submitForApproval($id, (int) ($user['user_id'] ?? 0));
        Response::success(['message' => 'Question submitted for approval']);
    }

    public function approve(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        $body = $this->readBody();
        $note = isset($body['note']) ? trim((string) $body['note']) : null;
        if ($note !== null && strlen($note) > 500) {
            Response::validationError(['note' => ['Note must not exceed 500 characters']]);
            return;
        }

        if ($question['current_version_id'] === null) {
            $this->repo->createVersion($id, (int) ($user['user_id'] ?? 0));
        }
        $this->repo->approve($id, (int) ($user['user_id'] ?? 0), $note);

        Response::success(['message' => 'Question approved']);
    }

    public function reject(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        $body = $this->readBody();
        $validator = new Validator($body);
        $validator->required(['note'])->max('note', 500);
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $this->repo->reject($id, (int) ($user['user_id'] ?? 0), trim((string) $body['note']));
        Response::success(['message' => 'Question rejected']);
    }

    public function fork(array $user, int $id): void
    {
        $this->requireBankRole($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        if ((int) $question['owner_user_id'] === (int) ($user['user_id'] ?? 0)) {
            Response::validationError(['question' => ['You already own this question']]);
            return;
        }

        $newId = $this->repo->fork($id, $user);
        if ($newId === null) {
            Response::notFound('Question not found');
            return;
        }

        Response::success(['question_id' => $newId], 'Question copied to your private bank', 201);
    }

    public function archive(array $user, int $id): void
    {
        $this->requireBankRole($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        $isAdmin = AccessControl::isSuperAdmin($user) || AccessControl::isAdmin($user);
        if (!$isAdmin && (int) $question['owner_user_id'] !== (int) ($user['user_id'] ?? 0)) {
            Response::forbidden('Only the creator or an administrator can archive this question');
            return;
        }

        $body = $this->readBody();
        $archived = !empty($body['is_archived']);
        $this->repo->setArchived($id, $archived);

        Response::success(['message' => $archived ? 'Question archived' : 'Question restored']);
    }

    public function versions(array $user, int $id): void
    {
        $this->requireBankRole($user);
        $question = $this->repo->findVisibleQuestion($id, $user);
        if (!$question) {
            Response::notFound('Question not found');
            return;
        }

        Response::success(['versions' => $this->repo->listVersions($id)]);
    }

    /* ------------------------------------------------------------------ */
    /* CSV import / export                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * POST /question-bank/questions/import
     *
     * Appends CSV rows as private drafts in the actor's bank. Duplicate text
     * is skipped; validation failures are reported per row.
     */
    public function importQuestions(array $user): void
    {
        $this->requireBankRole($user);
        $institutionId = $this->requireInstitution($user);

        $contents = $this->readUploadedCsv();
        if ($contents === null) {
            return; // readUploadedCsv already sent the error response
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
                $prepared[] = ['row' => $rowNumber, 'question' => $question];
            } catch (\Exception $e) {
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
            $summary = $this->repo->importQuestions($prepared, $user, $institutionId);
            $imported = (int) $summary['imported'];
            $skipped = (int) $summary['skipped'];
            $failed += (int) $summary['failed'];
            $results = array_merge($results, $summary['results']);
        }

        usort($results, static fn ($a, $b) => ((int) $a['row']) <=> ((int) $b['row']));

        Response::success([
            'message' => sprintf(
                'Imported %d question%s. Skipped %d duplicate%s. Failed %d row%s.',
                $imported,
                $imported === 1 ? '' : 's',
                $skipped,
                $skipped === 1 ? '' : 's',
                $failed,
                $failed === 1 ? '' : 's'
            ),
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'total_rows' => count($parsed['rows']),
            'results' => $results,
        ]);
    }

    /**
     * GET /question-bank/questions/export
     *
     * Exports the questions the current user can see, honouring the same
     * filters as the bank list page.
     */
    public function exportQuestions(array $user): void
    {
        $this->requireBankRole($user);

        $filters = [
            'category_id' => $_GET['category_id'] ?? null,
            'subject_id' => $_GET['subject_id'] ?? null,
            'topic_id' => $_GET['topic_id'] ?? null,
            'visibility' => $_GET['visibility'] ?? null,
            'approval_status' => $_GET['approval_status'] ?? null,
            'is_archived' => isset($_GET['is_archived']) ? (int) $_GET['is_archived'] : null,
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];

        $csv = QuestionCsv::serialize($this->repo->questionsForExport($filters, $user));
        $this->streamCsv($csv, 'question-bank-questions-' . date('Y-m-d-His') . '.csv');
    }

    /**
     * GET /question-bank/questions/import-template
     */
    public function importTemplate(array $user): void
    {
        $this->requireBankRole($user);
        $this->streamCsv(QuestionCsv::template(), 'question-bank-questions-import-template.csv');
    }

    /**
     * Validate and read the uploaded CSV file.
     *
     * Returns the file contents, or null after sending an error response.
     */
    private function readUploadedCsv(): ?string
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('No CSV file uploaded', 400);
            return null;
        }

        $file = $_FILES['file'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            Response::error($this->describeUploadError($uploadError), 400);
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            Response::error('The uploaded file could not be read', 400);
            return null;
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            Response::error('Please upload a .csv file', 422);
            return null;
        }
        if ($size <= 0) {
            Response::error('The uploaded CSV file is empty', 422);
            return null;
        }
        if ($size > QuestionCsv::MAX_FILE_BYTES) {
            Response::error('The CSV file is too large (maximum 2 MB)', 422);
            return null;
        }

        $contents = file_get_contents($tmpName);
        if (!is_string($contents) || trim($contents) === '') {
            Response::error('The uploaded CSV file is empty', 422);
            return null;
        }

        return $contents;
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

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    public function categoryIndex(array $user): void
    {
        $this->requireBankRole($user);
        $institutionId = $this->requireInstitution($user);
        Response::success(['categories' => $this->repo->listCategories($institutionId)]);
    }

    public function categoryCreate(array $user): void
    {
        $this->requireAdmin($user);
        $body = $this->readBody();
        $institutionId = $this->requireInstitution($user, $body);

        $validator = new Validator($body);
        $validator->required(['name'])->max('name', 100)->numeric('parent_id');
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $parentId = !empty($body['parent_id']) ? (int) $body['parent_id'] : null;
        if ($parentId !== null && !$this->repo->findCategory($parentId, $institutionId)) {
            Response::validationError(['parent_id' => ['Parent category was not found']]);
            return;
        }

        try {
            $id = $this->repo->createCategory($institutionId, (string) $body['name'], $parentId);
        } catch (\PDOException $e) {
            Response::validationError(['name' => ['A category with this name already exists']]);
            return;
        }

        Response::success(['category_id' => $id], 'Category created', 201);
    }

    public function categoryUpdate(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $body = $this->readBody();
        $institutionId = $this->requireInstitution($user, $body);

        if (!$this->repo->findCategory($id, $institutionId)) {
            Response::notFound('Category not found');
            return;
        }

        $validator = new Validator($body);
        $validator->required(['name'])->max('name', 100)->numeric('parent_id');
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $parentId = !empty($body['parent_id']) ? (int) $body['parent_id'] : null;
        if ($parentId === $id || ($parentId !== null && !$this->repo->findCategory($parentId, $institutionId))) {
            Response::validationError(['parent_id' => ['Invalid parent category']]);
            return;
        }

        try {
            $this->repo->updateCategory($id, $institutionId, (string) $body['name'], $parentId);
        } catch (\PDOException $e) {
            Response::validationError(['name' => ['A category with this name already exists']]);
            return;
        }

        Response::success(['message' => 'Category updated']);
    }

    public function categoryDelete(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $institutionId = $this->requireInstitution($user);

        if (!$this->repo->findCategory($id, $institutionId)) {
            Response::notFound('Category not found');
            return;
        }
        if ($this->repo->categoryInUse($id, $institutionId)) {
            Response::validationError(['category' => ['Category is used by one or more questions']]);
            return;
        }

        $this->repo->deleteCategory($id, $institutionId);
        Response::success(['message' => 'Category deleted']);
    }

    /* ------------------------------------------------------------------ */
    /* Topics                                                              */
    /* ------------------------------------------------------------------ */

    public function topicIndex(array $user): void
    {
        $this->requireBankRole($user);
        $institutionId = $this->requireInstitution($user);
        $subjectId = isset($_GET['subject_id']) && $_GET['subject_id'] !== ''
            ? (int) $_GET['subject_id']
            : null;

        Response::success(['topics' => $this->repo->listTopics($institutionId, $subjectId)]);
    }

    public function topicCreate(array $user): void
    {
        $this->requireAdmin($user);
        $body = $this->readBody();
        $institutionId = $this->requireInstitution($user, $body);

        $validator = new Validator($body);
        $validator->required(['name'])->max('name', 150)->numeric('subject_id');
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $subjectId = !empty($body['subject_id']) ? (int) $body['subject_id'] : null;
        if ($subjectId !== null && !$this->repo->subjectExists($subjectId, $institutionId)) {
            Response::validationError(['subject_id' => ['Subject was not found']]);
            return;
        }

        try {
            $id = $this->repo->createTopic($institutionId, $subjectId, (string) $body['name']);
        } catch (\PDOException $e) {
            Response::validationError(['name' => ['A topic with this name already exists']]);
            return;
        }

        Response::success(['topic_id' => $id], 'Topic created', 201);
    }

    public function topicUpdate(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $body = $this->readBody();
        $institutionId = $this->requireInstitution($user, $body);

        if (!$this->repo->findTopic($id, $institutionId)) {
            Response::notFound('Topic not found');
            return;
        }

        $validator = new Validator($body);
        $validator->required(['name'])->max('name', 150)->numeric('subject_id');
        if ($validator->fails()) {
            Response::validationError($validator->getErrors());
            return;
        }

        $subjectId = !empty($body['subject_id']) ? (int) $body['subject_id'] : null;
        if ($subjectId !== null && !$this->repo->subjectExists($subjectId, $institutionId)) {
            Response::validationError(['subject_id' => ['Subject was not found']]);
            return;
        }

        try {
            $this->repo->updateTopic($id, $institutionId, $subjectId, (string) $body['name']);
        } catch (\PDOException $e) {
            Response::validationError(['name' => ['A topic with this name already exists']]);
            return;
        }

        Response::success(['message' => 'Topic updated']);
    }

    public function topicDelete(array $user, int $id): void
    {
        $this->requireAdmin($user);
        $institutionId = $this->requireInstitution($user);

        if (!$this->repo->findTopic($id, $institutionId)) {
            Response::notFound('Topic not found');
            return;
        }
        if ($this->repo->topicInUse($id, $institutionId)) {
            Response::validationError(['topic' => ['Topic is used by one or more questions']]);
            return;
        }

        $this->repo->deleteTopic($id, $institutionId);
        Response::success(['message' => 'Topic deleted']);
    }

    /* ------------------------------------------------------------------ */

    private function normalizeOptions($options): array
    {
        $normalized = [];
        if (!is_array($options)) {
            return $normalized;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $label = strtoupper(trim((string) ($option['label'] ?? ($option['option_label'] ?? ''))));
            $text = trim((string) ($option['text'] ?? ($option['option_text'] ?? '')));
            if ($label === '' || $text === '') {
                continue;
            }
            $normalized[] = [
                'label' => $label,
                'text' => $text,
                'is_correct' => !empty($option['is_correct']) ? 1 : 0,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function metadataReferencesValid(array $data, int $institutionId): bool
    {
        foreach (['category_id' => 'category', 'topic_id' => 'topic'] as $field => $kind) {
            if (empty($data[$field])) {
                continue;
            }
            $id = (int) $data[$field];
            $found = $kind === 'category'
                ? $this->repo->findCategory($id, $institutionId)
                : $this->repo->findTopic($id, $institutionId);
            if (!$found) {
                return false;
            }
        }

        if (!empty($data['subject_id'])
            && !$this->repo->subjectExists((int) $data['subject_id'], $institutionId)) {
            return false;
        }

        return true;
    }
}
