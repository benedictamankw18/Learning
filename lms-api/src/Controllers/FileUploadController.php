<?php

namespace App\Controllers;

use App\Utils\Response;
use App\Security\AccessControl;

class FileUploadController
{
    private $uploadPath = __DIR__ . '/../../uploads/';
    private $maxFileSize = 104857600; // 100MB in bytes
    private $allowedTypes = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        'video' => ['mp4', 'avi', 'mov', 'wmv'],
        'audio' => ['mp3', 'wav', 'ogg'],
        'archive' => ['zip', 'rar', '7z']
    ];

    public function __construct()
    {
        // Create upload directory if it doesn't exist
        if (!file_exists($this->uploadPath)) {
            mkdir($this->uploadPath, 0777, true);
        }
    }

    /**
     * Resolve the institution that owns a new upload.
     *
     * Super admins must explicitly choose an institution; everyone else is
     * pinned to the institution carried by their authenticated session.
     */
    private function resolveUploadInstitutionId(array $user): int
    {
        if (AccessControl::isSuperAdmin($user)) {
            return isset($_POST['institution_id']) ? (int) $_POST['institution_id'] : 0;
        }

        return AccessControl::institutionId($user);
    }

    /**
     * Check whether the actor may read a file stored for an institution.
     */
    private function canAccessInstitution(array $user, int $institutionId): bool
    {
        if ($institutionId <= 0) {
            return false;
        }

        if (AccessControl::isSuperAdmin($user)) {
            $requested = (int) ($_GET['institution_id'] ?? 0);
            return $requested <= 0 || $requested === $institutionId;
        }

        return AccessControl::institutionId($user) === $institutionId;
    }

    /**
     * Locate a stored file for the current actor.
     *
     * Tenant users can only resolve files inside their own institution
     * directory. Super admins may target an institution explicitly or search
     * across institutions, and may still read legacy flat files.
     */
    private function findFile(array $user, string $category, string $filename, ?string $subdir = null): ?string
    {
        $relative = $category . '/' . ($subdir !== null ? $subdir . '/' : '') . $filename;

        if (AccessControl::isSuperAdmin($user)) {
            $requested = (int) ($_GET['institution_id'] ?? 0);
            if ($requested > 0) {
                $candidate = $this->uploadPath . $requested . '/' . $relative;
                return is_file($candidate) ? $candidate : null;
            }

            foreach (scandir($this->uploadPath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                    continue;
                }
                $candidate = $this->uploadPath . $entry . '/' . $relative;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            // Legacy flat file (pre multi-tenant uploads).
            $legacy = $this->uploadPath . $relative;
            return is_file($legacy) ? $legacy : null;
        }

        $institutionId = AccessControl::institutionId($user);
        if ($institutionId <= 0) {
            return null;
        }

        $candidate = $this->uploadPath . $institutionId . '/' . $relative;
        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Stream a resolved file to the client.
     */
    private function streamFile(string $filePath): void
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=86400');
        readfile($filePath);
        exit;
    }

    /**
     * Upload single file
     * POST /upload
     */
    public function upload(array $user): void
    {
        try {
            if (!isset($_FILES['file'])) {
                Response::error('No file uploaded', 400);
                return;
            }

            $file = $_FILES['file'];
            $category = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['category'] ?? 'general')) ?: 'general';
            $userId = $user['user_id'] ?? null;

            // Resolve and require an institution for every stored upload.
            $institutionId = $this->resolveUploadInstitutionId($user);
            if ($institutionId <= 0) {
                Response::error('Institution context is required', 400);
                return;
            }

            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                Response::error($validation['message'], 400);
                return;
            }

            // Generate unique filename
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = uniqid() . '_' . time() . '.' . $ext;

            // Determine upload path (institution scoped)
            $categoryPath = $this->uploadPath . $institutionId . '/' . $category . '/';
            if (!file_exists($categoryPath)) {
                mkdir($categoryPath, 0777, true);
            }

            $filePath = $categoryPath . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                Response::error('Failed to save file', 500);
                return;
            }

            // File metadata
            $fileData = [
                'original_name' => $file['name'],
                'filename' => $filename,
                'path' => $institutionId . '/' . $category . '/' . $filename,
                'size' => $file['size'],
                'type' => $file['type'],
                'extension' => $ext,
                'category' => $category,
                'institution_id' => $institutionId,
                'uploaded_by' => $userId,
                'url' => '/uploads/' . $institutionId . '/' . $category . '/' . $filename,
                'api_url' => '/api/upload/' . $category . '/' . $filename
            ];

            Response::success($fileData, 'File uploaded successfully');
        } catch (\Exception $e) {
            Response::serverError('Failed to upload file: ' . $e->getMessage());
        }
    }

    /**
     * Upload multiple files
     * POST /upload/multiple
     */
    public function uploadMultiple(array $user): void
    {
        try {
            if (!isset($_FILES['files'])) {
                Response::error('No files uploaded', 400);
                return;
            }

            $files = $_FILES['files'];
            $category = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['category'] ?? 'general')) ?: 'general';
            $userId = $user['user_id'] ?? null;

            $institutionId = $this->resolveUploadInstitutionId($user);
            if ($institutionId <= 0) {
                Response::error('Institution context is required', 400);
                return;
            }

            $uploadedFiles = [];
            $errors = [];

            // Handle multiple files
            for ($i = 0; $i < count($files['name']); $i++) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];

                // Validate file
                $validation = $this->validateFile($file);
                if (!$validation['valid']) {
                    $errors[] = [
                        'file' => $file['name'],
                        'message' => $validation['message']
                    ];
                    continue;
                }

                // Generate unique filename
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = uniqid() . '_' . time() . '_' . $i . '.' . $ext;

                // Determine upload path (institution scoped)
                $categoryPath = $this->uploadPath . $institutionId . '/' . $category . '/';
                if (!file_exists($categoryPath)) {
                    mkdir($categoryPath, 0777, true);
                }

                $filePath = $categoryPath . $filename;

                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $uploadedFiles[] = [
                        'original_name' => $file['name'],
                        'filename' => $filename,
                        'path' => $institutionId . '/' . $category . '/' . $filename,
                        'size' => $file['size'],
                        'type' => $file['type'],
                        'extension' => $ext,
                        'category' => $category,
                        'institution_id' => $institutionId,
                        'uploaded_by' => $userId,
                        'url' => '/uploads/' . $institutionId . '/' . $category . '/' . $filename,
                        'api_url' => '/api/upload/' . $category . '/' . $filename
                    ];
                } else {
                    $errors[] = [
                        'file' => $file['name'],
                        'message' => 'Failed to save file'
                    ];
                }
            }

            Response::success([
                'uploaded' => $uploadedFiles,
                'errors' => $errors
            ], count($uploadedFiles) . ' file(s) uploaded successfully');
        } catch (\Exception $e) {
            Response::serverError('Failed to upload files: ' . $e->getMessage());
        }
    }

    /**
     * Delete file
     * DELETE /upload/{category}/{filename}
     */
    public function delete(array $user, string $category, string $filename): void
    {
        try {
            if (!AccessControl::isAdmin($user) && !AccessControl::isSuperAdmin($user)) {
                Response::forbidden('Admin access required');
                return;
            }

            $safeCategory = preg_replace('/[^a-zA-Z0-9_-]/', '', $category);
            $safeFilename = basename($filename);
            $filePath = $this->findFile($user, $safeCategory, $safeFilename);

            if ($filePath === null) {
                Response::notFound('File not found');
                return;
            }

            if (unlink($filePath)) {
                Response::success(null, 'File deleted successfully');
            } else {
                Response::error('Failed to delete file', 500);
            }
        } catch (\Exception $e) {
            Response::serverError('Failed to delete file: ' . $e->getMessage());
        }
    }

    /**
     * Validate file upload
     */
    private function validateFile($file)
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'message' => 'File upload error: ' . $this->getUploadErrorMessage($file['error'])
            ];
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            return [
                'valid' => false,
                'message' => 'File size exceeds maximum allowed size of ' . ($this->maxFileSize / 1048576) . 'MB'
            ];
        }

        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allAllowed = array_merge(...array_values($this->allowedTypes));

        if (!in_array($ext, $allAllowed)) {
            return [
                'valid' => false,
                'message' => 'File type not allowed. Allowed types: ' . implode(', ', $allAllowed)
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Get file info
     * GET /upload/{category}/{filename}/info
     */
    public function getFileInfo(array $user, string $category, string $filename): void
    {
        try {
            if (!AccessControl::isAdmin($user) && !AccessControl::isSuperAdmin($user)) {
                Response::forbidden('Admin access required');
                return;
            }

            $safeCategory = preg_replace('/[^a-zA-Z0-9_-]/', '', $category);
            $safeFilename = basename($filename);
            $filePath = $this->findFile($user, $safeCategory, $safeFilename);

            if ($filePath === null) {
                Response::notFound('File not found');
                return;
            }

            $relativePath = str_replace('\\', '/', substr($filePath, strlen($this->uploadPath)));

            $info = [
                'filename' => $safeFilename,
                'path' => $relativePath,
                'size' => filesize($filePath),
                'type' => mime_content_type($filePath),
                'extension' => pathinfo($safeFilename, PATHINFO_EXTENSION),
                'category' => $safeCategory,
                'url' => '/uploads/' . $relativePath,
                'api_url' => '/api/upload/' . $safeCategory . '/' . $safeFilename,
                'created_at' => date('Y-m-d H:i:s', filectime($filePath)),
                'modified_at' => date('Y-m-d H:i:s', filemtime($filePath))
            ];

            Response::success($info);
        } catch (\Exception $e) {
            Response::serverError('Failed to get file info: ' . $e->getMessage());
        }
    }

    /**
     * Serve uploaded file bytes
     * GET /upload/{category}/{filename}
     */
    public function serve(array $user, string $category, string $filename): void
    {
        try {
            $safeCategory = preg_replace('/[^a-zA-Z0-9_-]/', '', $category);
            $safeFilename = basename($filename);
            $filePath = $this->findFile($user, $safeCategory, $safeFilename);

            if ($filePath === null) {
                Response::notFound('File not found');
                return;
            }

            $this->streamFile($filePath);
        } catch (\Exception $e) {
            Response::serverError('Failed to serve file: ' . $e->getMessage());
        }
    }

    /**
     * Serve uploaded file bytes for one-level nested folders
     * GET /upload/{category}/{subdir}/{filename}
     */
    public function serveNested(array $user, string $category, string $subdir, string $filename): void
    {
        try {
            $safeCategory = preg_replace('/[^a-zA-Z0-9_-]/', '', $category);
            $safeSubdir = preg_replace('/[^a-zA-Z0-9_-]/', '', $subdir);
            $safeFilename = basename($filename);

            // New scoped URLs use /api/upload/<institution_id>/<category>/<filename>.
            if ($safeCategory !== '' && ctype_digit($safeCategory)) {
                $pathInstitutionId = (int) $safeCategory;
                if (!$this->canAccessInstitution($user, $pathInstitutionId)) {
                    Response::notFound('File not found');
                    return;
                }

                $filePath = $this->uploadPath . $pathInstitutionId . '/' . $safeSubdir . '/' . $safeFilename;
                if (!is_file($filePath)) {
                    Response::notFound('File not found');
                    return;
                }

                $this->streamFile($filePath);
                return;
            }

            $filePath = $this->findFile($user, $safeCategory, $safeFilename, $safeSubdir);

            if ($filePath === null) {
                Response::notFound('File not found');
                return;
            }

            $this->streamFile($filePath);
        } catch (\Exception $e) {
            Response::serverError('Failed to serve file: ' . $e->getMessage());
        }
    }
}
