<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentSystem;
use App\Services\MinioService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FileUploadController extends Controller
{
    protected $minioService;

    public function __construct(MinioService $minioService)
    {
        $this->minioService = $minioService;
    }

    /**
     * Generate presigned URL for file upload and log to DocumentSystem
     */
    public function generateUploadUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_name' => 'required|string|max:50',
            'module_id' => 'required|integer',
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|max:100',
            'file_size' => 'nullable|integer|min:1',
            'note' => 'nullable|string',
            'expiration' => 'nullable|integer|min:60|max:86400', // 1 min to 24 hours
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        
        try {
            // Get authenticated user ID from JWT middleware
            $authUserId = $request->user()->id ?? null;
            
            if (!$authUserId) {
                throw new \Exception('User ID not found in JWT token');
            }

            $timestamp = Carbon::now()->timestamp;

            // Validate file type and size
            $contentType = $request->input('content_type');
            $fileSize = $request->input('file_size', 0);

            // Generate presigned URL
            $result = $this->minioService->generatePresignedUploadUrl(
                moduleName: $request->input('module_name'),
                moduleNameId: (string) $request->input('module_id'),
                filename: $request->input('filename'),
                contentType: $contentType,
                fileSize: $fileSize,
                expiration: $request->input('expiration')
            );

            // Create DocumentSystem record
            $documentId = $timestamp . random_numbersu(5);
            
            $document = DocumentSystem::create([
                'DocumentID' => $documentId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ModuleName' => $request->input('module_name'),
                'ModuleID' => $request->input('module_id'),
                'FileName' => $result['file_info']['filename'],
                'FilePath' => $result['file_info']['path'],
                'Note' => $request->input('note'),
                'IsDelete' => false,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'DocumentSystem',
                'ReferenceRecordID' => $document->DocumentID,
                'Data' => json_encode([
                    'ModuleName' => $document->ModuleName,
                    'ModuleID' => $document->ModuleID,
                    'FileName' => $document->FileName,
                    'FilePath' => $document->FilePath,
                    'ContentType' => $contentType,
                    'FileSize' => $fileSize,
                ]),
                'Note' => 'Document upload URL generated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload URL generated successfully',
                'data' => [
                    'document_id' => $document->DocumentID,
                    'upload_url' => $result['upload_url'],
                    'file_id' => $result['file_info']['id'],
                    'file_path' => $result['file_info']['path'],
                    'filename' => $result['file_info']['filename'],
                    'expires_in' => $result['expires_in'],
                    'content_type' => $result['content_type'],
                    'max_file_size' => $this->minioService->getMaxFileSizeFormatted(),
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate upload URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate presigned URLs for batch file upload (maximum 5 files)
     */
    public function generateBatchUploadUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_name' => 'required|string|max:50',
            'module_id' => 'required|integer',
            'files' => 'required|array|min:1|max:5',
            'files.*.filename' => 'required|string|max:255',
            'files.*.content_type' => 'required|string|max:100',
            'files.*.file_size' => 'nullable|integer|min:1',
            'files.*.note' => 'nullable|string',
            'expiration' => 'nullable|integer|min:60|max:86400',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        
        try {
            $authUserId = $request->user()->id ?? null;
            
            if (!$authUserId) {
                throw new \Exception('User ID not found in JWT token');
            }

            $files = $request->input('files');
            $moduleName = $request->input('module_name');
            $moduleId = $request->input('module_id');
            $expiration = $request->input('expiration');
            
            $results = [];
            $failedFiles = [];

            foreach ($files as $index => $fileData) {
                try {
                    $timestamp = Carbon::now()->timestamp;
                    $contentType = $fileData['content_type'];
                    $fileSize = $fileData['file_size'] ?? 0;

                    // Generate presigned URL
                    $result = $this->minioService->generatePresignedUploadUrl(
                        moduleName: $moduleName,
                        moduleNameId: (string) $moduleId,
                        filename: $fileData['filename'],
                        contentType: $contentType,
                        fileSize: $fileSize,
                        expiration: $expiration
                    );

                    // Create DocumentSystem record
                    $documentId = $timestamp . random_numbersu(5);
                    
                    $document = DocumentSystem::create([
                        'DocumentID' => $documentId,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ModuleName' => $moduleName,
                        'ModuleID' => $moduleId,
                        'FileName' => $result['file_info']['filename'],
                        'FilePath' => $result['file_info']['path'],
                        'Note' => $fileData['note'] ?? null,
                        'IsDelete' => false,
                    ]);

                    // Create audit log
                    AuditLog::create([
                        'AuditLogID' => $timestamp . random_numbersu(5),
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'DocumentSystem',
                        'ReferenceRecordID' => $document->DocumentID,
                        'Data' => json_encode([
                            'ModuleName' => $document->ModuleName,
                            'ModuleID' => $document->ModuleID,
                            'FileName' => $document->FileName,
                            'FilePath' => $document->FilePath,
                            'ContentType' => $contentType,
                            'FileSize' => $fileSize,
                            'BatchIndex' => $index,
                        ]),
                        'Note' => 'Batch document upload URL generated'
                    ]);

                    $results[] = [
                        'index' => $index,
                        'original_filename' => $fileData['filename'],
                        'document_id' => $document->DocumentID,
                        'upload_url' => $result['upload_url'],
                        'file_id' => $result['file_info']['id'],
                        'file_path' => $result['file_info']['path'],
                        'filename' => $result['file_info']['filename'],
                        'expires_in' => $result['expires_in'],
                        'content_type' => $result['content_type'],
                    ];

                } catch (\Exception $e) {
                    $failedFiles[] = [
                        'index' => $index,
                        'filename' => $fileData['filename'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            // If all files failed, rollback
            if (empty($results)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'All files failed to process',
                    'failed_files' => $failedFiles
                ], 500);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Batch upload URLs generated successfully',
                'data' => [
                    'total_requested' => count($files),
                    'total_success' => count($results),
                    'total_failed' => count($failedFiles),
                    'files' => $results,
                    'failed_files' => $failedFiles,
                    'max_file_size' => $this->minioService->getMaxFileSizeFormatted(),
                ]
            ], count($failedFiles) > 0 ? 207 : 200); // 207 = Multi-Status (partial success)

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate batch upload URLs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate presigned URL for file download
     */
    public function generateDownloadUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:DocumentSystem,DocumentID',
            'expiration' => 'nullable|integer|min:60|max:86400',
            'force_download' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $authUserId = $request->user()->id ?? null;
            $documentId = $request->input('document_id');

            // Get document from database
            $document = DocumentSystem::where('DocumentID', $documentId)
                ->where('IsDelete', false)
                ->first();

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found or has been deleted'
                ], 404);
            }

            // Check if file exists in MinIO
            if (!$this->minioService->fileExists($document->FilePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found in storage'
                ], 404);
            }

            $forceDownload = $request->input('force_download', false);
            $expiration = $request->input('expiration');

            // Generate appropriate URL
            if ($forceDownload) {
                $url = $this->minioService->generatePresignedDownloadUrl(
                    path: $document->FilePath,
                    expiration: $expiration,
                    forceDownload: true,
                    downloadFilename: $document->FileName
                );
            } else {
                $url = $this->minioService->generatePresignedViewUrl(
                    path: $document->FilePath,
                    expiration: $expiration
                );
            }

            // Log download action
            $timestamp = Carbon::now()->timestamp;
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'R',
                'ReferenceTable' => 'DocumentSystem',
                'ReferenceRecordID' => $document->DocumentID,
                'Data' => json_encode([
                    'FileName' => $document->FileName,
                    'FilePath' => $document->FilePath,
                    'ForceDownload' => $forceDownload,
                ]),
                'Note' => $forceDownload ? 'Document download URL generated' : 'Document view URL generated'
            ]);

            return response()->json([
                'success' => true,
                'message' => $forceDownload ? 'Download URL generated successfully' : 'View URL generated successfully',
                'data' => [
                    'url' => $url,
                    'document_id' => $document->DocumentID,
                    'filename' => $document->FileName,
                    'file_path' => $document->FilePath,
                    'force_download' => $forceDownload,
                    'expires_in' => $expiration ?? config('filesystems.disks.minio.url_expiration')
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate presigned URL for viewing file inline (shorthand)
     */
    public function generateViewUrl(Request $request)
    {
        $request->merge(['force_download' => false]);
        return $this->generateDownloadUrl($request);
    }

    /**
     * Get document list by module
     */
    public function getDocumentsByModule(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_name' => 'required|string|max:50',
            'module_id' => 'required|integer',
            'include_deleted' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = DocumentSystem::byModule(
                $request->input('module_name'),
                $request->input('module_id')
            );

            if (!$request->input('include_deleted', false)) {
                $query->active();
            }

            $documents = $query->orderBy('AtTimeStamp', 'desc')->get();

            return response()->json([
                'success' => true,
                'message' => 'Documents retrieved successfully',
                'data' => $documents
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete document
     */
    public function deleteDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:DocumentSystem,DocumentID',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $authUserId = $request->user()->id ?? null;
            $documentId = $request->input('document_id');
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentSystem::where('DocumentID', $documentId)
                ->where('IsDelete', false)
                ->first();

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found or already deleted'
                ], 404);
            }

            // Soft delete
            $document->IsDelete = true;
            $document->save();

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'DocumentSystem',
                'ReferenceRecordID' => $document->DocumentID,
                'Data' => json_encode([
                    'FileName' => $document->FileName,
                    'FilePath' => $document->FilePath,
                    'ModuleName' => $document->ModuleName,
                    'ModuleID' => $document->ModuleID,
                ]),
                'Note' => 'Document soft deleted'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully',
                'data' => [
                    'document_id' => $document->DocumentID,
                    'filename' => $document->FileName
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update document information
     */
    public function updateDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:DocumentSystem,DocumentID',
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $authUserId = $request->user()->id ?? null;
            $documentId = $request->input('document_id');
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentSystem::where('DocumentID', $documentId)
                ->where('IsDelete', false)
                ->first();

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found'
                ], 404);
            }

            $oldData = [
                'Note' => $document->Note,
            ];

            // Update document
            if ($request->has('note')) {
                $document->Note = $request->input('note');
            }
            $document->save();

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentSystem',
                'ReferenceRecordID' => $document->DocumentID,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => [
                        'Note' => $document->Note,
                    ]
                ]),
                'Note' => 'Document information updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $document
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get allowed file types
     */
    public function getAllowedFileTypes(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Allowed file types retrieved successfully',
            'data' => [
                'mime_types' => $this->minioService->getAllowedMimeTypes(),
                'max_file_size' => $this->minioService->getMaxFileSizeFormatted(),
                'max_file_size_bytes' => $this->minioService->getMaxFileSize(),
                'allowed_extensions' => [
                    'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
                    'documents' => ['pdf', 'doc', 'docx'],
                    'spreadsheets' => ['xls', 'xlsx']
                ]
            ]
        ], 200);
    }
}