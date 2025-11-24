<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MinioService
{
    protected $client;
    protected $bucket;
    protected $urlExpiration;
    
    // Allowed file types (MIME types)
    protected $allowedMimeTypes = [
        // Images
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        // PDF
        'application/pdf',
        // Word Documents
        'application/msword', // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        // Excel
        'application/vnd.ms-excel', // .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
    ];
    
    // Maximum file size in bytes (512 MB)
    protected $maxFileSize = 536870912; // 512 * 1024 * 1024

    public function __construct()
    {
        $this->bucket = config('filesystems.disks.minio.bucket');
        $this->urlExpiration = config('filesystems.disks.minio.url_expiration', 3600);
        
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.minio.region'),
            'endpoint' => config('filesystems.disks.minio.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.minio.use_path_style_endpoint'),
            'credentials' => [
                'key' => config('filesystems.disks.minio.key'),
                'secret' => config('filesystems.disks.minio.secret'),
            ],
        ]);

        $this->ensureBucketExists();
    }

    /**
     * Ensure bucket exists, create if not
     */
    protected function ensureBucketExists()
    {
        try {
            if (!$this->client->doesBucketExist($this->bucket)) {
                $this->client->createBucket([
                    'Bucket' => $this->bucket,
                ]);
                Log::info("MinIO bucket created: {$this->bucket}");
            }
        } catch (AwsException $e) {
            Log::error("MinIO bucket error: " . $e->getMessage());
        }
    }

    /**
     * Validate file type
     */
    public function validateFileType(string $contentType): bool
    {
        return in_array($contentType, $this->allowedMimeTypes);
    }

    /**
     * Validate file size
     */
    public function validateFileSize(int $fileSize): bool
    {
        return $fileSize <= $this->maxFileSize;
    }

    /**
     * Get allowed file types
     */
    public function getAllowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    /**
     * Get max file size in bytes
     */
    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    /**
     * Get max file size in human readable format
     */
    public function getMaxFileSizeFormatted(): string
    {
        return '512 MB';
    }

    /**
     * Generate file path with pattern: bucket/${module_name}/${module_name_id}/${id}_snake(${filename})
     */
    public function generateFilePath(string $moduleName, string $moduleNameId, string $filename): array
    {
        $id = (string) Str::uuid();
        $snakeFilename = Str::snake(pathinfo($filename, PATHINFO_FILENAME));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $finalFilename = $extension 
            ? "{$snakeFilename}_{$id}.{$extension}" 
            : "{$snakeFilename}_{$id}";
        
        $path = "{$moduleName}/{$moduleNameId}/{$finalFilename}";
        
        return [
            'id' => $id,
            'path' => $path,
            'filename' => $finalFilename,
            'original_filename' => $filename,
        ];
    }

    /**
     * Generate presigned URL for PUT operation (upload)
     */
    public function generatePresignedUploadUrl(
        string $moduleName, 
        string $moduleNameId, 
        string $filename,
        string $contentType = 'application/octet-stream',
        int $fileSize = 0,
        int $expiration = null
    ): array {
        // Validate file type
        if (!$this->validateFileType($contentType)) {
            throw new \Exception("File type not allowed. Allowed types: images, PDF, Word documents, Excel files");
        }

        // Validate file size
        if ($fileSize > 0 && !$this->validateFileSize($fileSize)) {
            throw new \Exception("File size exceeds maximum allowed size of {$this->getMaxFileSizeFormatted()}");
        }

        $fileInfo = $this->generateFilePath($moduleName, $moduleNameId, $filename);
        $expiration = $expiration ?? $this->urlExpiration;

        try {
            $cmd = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $fileInfo['path'],
                'ContentType' => $contentType,
            ]);

            $request = $this->client->createPresignedRequest($cmd, "+{$expiration} seconds");
            $presignedUrl = (string) $request->getUri();

            return [
                'upload_url' => $presignedUrl,
                'file_info' => $fileInfo,
                'expires_in' => $expiration,
                'content_type' => $contentType,
            ];
        } catch (AwsException $e) {
            Log::error("MinIO presigned URL error: " . $e->getMessage());
            throw new \Exception("Failed to generate upload URL: " . $e->getMessage());
        }
    }

    /**
     * Generate presigned URL for GET operation (download/view)
     */
    public function generatePresignedDownloadUrl(
        string $path, 
        int $expiration = null, 
        bool $forceDownload = false,
        string $downloadFilename = null
    ): string {
        $expiration = $expiration ?? $this->urlExpiration;

        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $path,
            ];

            // Force download with custom filename
            if ($forceDownload && $downloadFilename) {
                $params['ResponseContentDisposition'] = 'attachment; filename="' . $downloadFilename . '"';
            } elseif ($forceDownload) {
                $params['ResponseContentDisposition'] = 'attachment';
            }
            // If not forcing download, browser will display inline (view) for supported types

            $cmd = $this->client->getCommand('GetObject', $params);

            $request = $this->client->createPresignedRequest($cmd, "+{$expiration} seconds");
            return (string) $request->getUri();
        } catch (AwsException $e) {
            Log::error("MinIO download URL error: " . $e->getMessage());
            throw new \Exception("Failed to generate download URL: " . $e->getMessage());
        }
    }

    /**
     * Generate presigned URL for viewing file inline (in browser)
     */
    public function generatePresignedViewUrl(string $path, int $expiration = null): string
    {
        return $this->generatePresignedDownloadUrl($path, $expiration, false);
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $path): bool
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $path);
        } catch (AwsException $e) {
            Log::error("MinIO file check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete file
     */
    public function deleteFile(string $path): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
            return true;
        } catch (AwsException $e) {
            Log::error("MinIO delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file metadata
     */
    public function getFileMetadata(string $path): ?array
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return [
                'size' => $result['ContentLength'],
                'content_type' => $result['ContentType'],
                'last_modified' => $result['LastModified'],
                'etag' => trim($result['ETag'], '"'),
            ];
        } catch (AwsException $e) {
            Log::error("MinIO metadata error: " . $e->getMessage());
            return null;
        }
    }
}