<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentManagement;
use App\Models\DocumentVersion;
use App\Models\RaciActivity;
use App\Models\DocumentRole;
use App\Models\Employee;
use App\Models\Position;
use App\Services\MinioService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DocumentManagementController extends Controller
{
    protected $minioService;

    public function __construct(MinioService $minioService)
    {
        $this->minioService = $minioService;
    }

    /**
     * Create new document with version 1 and generate upload URL
     */
    public function createDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_name' => 'required|string|max:255|unique:DocumentManagement,DocumentName',
            'document_type' => 'required|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',

            // File upload info
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|max:100',
            'file_size' => 'nullable|integer|min:1',

            // Converted PDF (if original is not PDF)
            'has_converted_pdf' => 'nullable|boolean',
            'converted_filename' => 'nullable|required_if:has_converted_pdf,true|string|max:255',
            'converted_file_size' => 'nullable|integer|min:1',

            // RACI Activities (only if DocumentType = 'RACI')
            'raci_activities' => 'nullable|array',
            'raci_activities.*.activity' => 'required_with:raci_activities|string|max:255',
            'raci_activities.*.pic' => 'required_with:raci_activities|integer|exists:Position,PositionID',
            'raci_activities.*.status' => 'required_with:raci_activities|in:Informed,Accountable,Consulted',

            // Document Roles - multiple organizations that can access
            'access_organization_ids' => 'nullable|array',
            'access_organization_ids.*' => 'integer|exists:Organization,OrganizationID',
            'is_download' => 'nullable|boolean',
            'is_comment' => 'nullable|boolean',
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
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;
            $documentType = $request->input('document_type');
            $hasConvertedPdf = $request->input('has_converted_pdf', false);

            // Validate RACI activities only required if DocumentType = 'RACI'
            if ($documentType === 'RACI' && !$request->has('raci_activities')) {
                throw new \Exception('RACI activities are required for DocumentType = RACI');
            }

            // Create DocumentManagement
            $document = DocumentManagement::create([
                'DocumentManagementID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'DocumentName' => $request->input('document_name'),
                'DocumentType' => $documentType,
                'Description' => $request->input('description'),
                'Notes' => $request->input('notes'),
                'OrganizationID' => $request->input('organization_id'),
                'LatestVersionNo' => 1,
            ]);

            // ========================================
            // CASE 1: PDF Upload (No conversion needed)
            // ========================================
            if (!$hasConvertedPdf) {
                // Generate presigned URL for PDF
                $originalResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $document->DocumentManagementID,
                    filename: $request->input('filename'),
                    contentType: $request->input('content_type'),
                    fileSize: $request->input('file_size', 0)
                );

                $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $originalResult['file_info']['path'];

                // Create DocumentVersion (PDF only)
                DocumentVersion::create([
                    'DocumentManagementID' => $document->DocumentManagementID,
                    'VersionNo' => 1,
                    'DocumentPath' => $originalResult['file_info']['path'], // PDF for display
                    'DocumentUrl' => $pdfStaticUrl,
                    'DocumentOriginalPath' => null, // No original (already PDF)
                    'DocumentOriginalUrl' => null,
                    'AtTimeStamp' => $timestamp,
                ]);

                $responseData = [
                    'document_id' => $document->DocumentManagementID,
                    'document_name' => $document->DocumentName,
                    'document_type' => $document->DocumentType,
                    'version_no' => 1,
                    'upload_url' => $originalResult['upload_url'],
                    'file_path' => $originalResult['file_info']['path'],
                    'filename' => $originalResult['file_info']['filename'],
                    'expires_in' => $originalResult['expires_in'],
                ];
            }  // ========================================
            // CASE 2: Non-PDF Upload (Needs conversion)
            // ========================================
            else {
                // Generate presigned URL for ORIGINAL file (DOCX, XLSX, etc)
                $originalResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $document->DocumentManagementID,
                    filename: $request->input('filename'),
                    contentType: $request->input('content_type'),
                    fileSize: $request->input('file_size', 0)
                );

                $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $originalResult['file_info']['path'];

                // Generate presigned URL for CONVERTED PDF
                $convertedResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $document->DocumentManagementID,
                    filename: $request->input('converted_filename'),
                    contentType: 'application/pdf',
                    fileSize: $request->input('converted_file_size', 0)
                );

                $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $convertedResult['file_info']['path'];

                // Create DocumentVersion (PDF + Original)
                DocumentVersion::create([
                    'DocumentManagementID' => $document->DocumentManagementID,
                    'VersionNo' => 1,
                    'DocumentPath' => $convertedResult['file_info']['path'], // Converted PDF (for display)
                    'DocumentUrl' => $convertedStaticUrl,
                    'DocumentOriginalPath' => $originalResult['file_info']['path'], // Original file (for download)
                    'DocumentOriginalUrl' => $originalStaticUrl,
                    'AtTimeStamp' => $timestamp,
                ]);

                $responseData = [
                    'document_id' => $document->DocumentManagementID,
                    'document_name' => $document->DocumentName,
                    'document_type' => $document->DocumentType,
                    'version_no' => 1,
                    'upload_url' => $convertedResult['upload_url'],
                    'file_path' => $convertedResult['file_info']['path'],
                    'filename' => $convertedResult['file_info']['filename'],
                    'expires_in' => $convertedResult['expires_in'],
                    'original_upload_url' => $originalResult['upload_url'],
                    'original_file_path' => $originalResult['file_info']['path'],
                    'original_filename' => $originalResult['file_info']['filename'],
                    'original_expires_in' => $originalResult['expires_in'],
                ];
            }

            // // Generate presigned URL for version 1
            // $result = $this->minioService->generatePresignedUploadUrl(
            //     moduleName: 'DocumentManagement',
            //     moduleNameId: (string) $document->DocumentManagementID,
            //     filename: $request->input('filename'),
            //     contentType: $request->input('content_type'),
            //     fileSize: $request->input('file_size', 0)
            // );

            // $staticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
            //     . '/' . config('filesystems.disks.minio.bucket')
            //     . '/' . $result['file_info']['path'];

            // // Create DocumentVersion v1
            // DocumentVersion::create([
            //     'DocumentManagementID' => $document->DocumentManagementID,
            //     'VersionNo' => 1,
            //     'DocumentPath' => $result['file_info']['path'],
            //     'DocumentUrl'  => $staticUrl,
            //     'AtTimeStamp' => $timestamp,
            // ]);

            // Insert DocumentRole for owner organization (OrganizationID from DocumentManagement)
            DocumentRole::create([
                'DocumentRoleID' => Carbon::now()->timestamp . random_numbersu(5),
                'DocumentManagementID' => $document->DocumentManagementID,
                'OrganizationID' => $document->OrganizationID,
                'IsDownload' => $request->input('is_download', true),
                'IsComment' => $request->input('is_comment', true),
            ]);

            // Insert DocumentRole for additional access organizations
            if ($request->has('access_organization_ids')) {
                $accessOrgIds = $request->input('access_organization_ids');
                $isDownload = $request->input('is_download', true);
                $isComment = $request->input('is_comment', true);

                foreach ($accessOrgIds as $orgId) {
                    // Skip if same as owner organization (already inserted)
                    if ($orgId != $document->OrganizationID) {
                        DocumentRole::create([
                            'DocumentRoleID' => Carbon::now()->timestamp . random_numbersu(5),
                            'DocumentManagementID' => $document->DocumentManagementID,
                            'OrganizationID' => $orgId,
                            'IsDownload' => $isDownload,
                            'IsComment' => $isComment,
                        ]);
                    }
                }
            }

            // Create RACI Activities ONLY if DocumentType = 'RACI'
            if ($documentType === 'RACI' && $request->has('raci_activities')) {
                foreach ($request->input('raci_activities') as $raciData) {
                    RaciActivity::create([
                        'RaciActivityID' => Carbon::now()->timestamp . random_numbersu(5),
                        'DocumentManagementID' => $document->DocumentManagementID,
                        'Activity' => $raciData['activity'],
                        'PIC' => $raciData['pic'], // PIC is PositionID
                        'Status' => $raciData['status'],
                    ]);
                }
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'DocumentManagement',
                'ReferenceRecordID' => $document->DocumentManagementID,
                'Data' => json_encode([
                    'DocumentManagementID' => $document->DocumentManagementID,
                    'DocumentName' => $document->DocumentName,
                    'DocumentType' => $document->DocumentType,
                    'OrganizationID' => $document->OrganizationID,
                    'VersionNo' => 1,
                    'HasConvertedPDF' => $hasConvertedPdf,
                    'DocumentPath' => !$hasConvertedPdf ? $originalResult['file_info']['path'] : $convertedResult['file_info']['path'],
                    'DocumentOriginalPath' =>   $originalResult['file_info']['path'],
                    'AccessOrganizations' => array_merge(
                        [$document->OrganizationID],
                        $request->input('access_organization_ids', [])
                    ),
                ]),
                'Note' => 'Document created with version 1'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document created successfully',
                'data' => $responseData
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update document - create new version (current + 1)
     */
    public function updateDocument(Request $request, $documentId)
    {
        $validator = Validator::make($request->all(), [
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|max:100',
            'file_size' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',

            // Converted PDF (if original is not PDF)
            'has_converted_pdf' => 'nullable|boolean',
            'converted_filename' => 'nullable|required_if:has_converted_pdf,true|string|max:255',
            'converted_file_size' => 'nullable|integer|min:1',
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
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;
            $hasConvertedPdf = $request->input('has_converted_pdf', false);

            // Check if document exists
            $document = DocumentManagement::findOrFail($documentId);

            // Get current latest version
            $currentVersion = $document->LatestVersionNo ?? 1;
            $newVersionNo = $currentVersion + 1;

            // ========================================
            // CASE 1: PDF Upload (No conversion needed)
            // ========================================
            if (!$hasConvertedPdf) {
                $originalResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $documentId,
                    filename: $request->input('filename'),
                    contentType: $request->input('content_type'),
                    fileSize: $request->input('file_size', 0)
                );

                $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $originalResult['file_info']['path'];

                // Create new DocumentVersion (PDF only)
                DocumentVersion::create([
                    'DocumentManagementID' => $documentId,
                    'VersionNo' => $newVersionNo,
                    'DocumentPath' => $originalResult['file_info']['path'],
                    'DocumentUrl' => $pdfStaticUrl,
                    'DocumentOriginalPath' => null,
                    'DocumentOriginalUrl' => null,
                    'AtTimeStamp' => $timestamp,
                ]);

                $responseData = [
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'previous_version' => $currentVersion,
                    'current_version' => $newVersionNo,
                    'upload_url' => $originalResult['upload_url'],
                    'file_path' => $originalResult['file_info']['path'],
                    'filename' => $originalResult['file_info']['filename'],
                    'expires_in' => $originalResult['expires_in'],
                ];
            }
            // ========================================
            // CASE 2: Non-PDF Upload (Needs conversion)
            // ========================================
            else {
                // Generate presigned URL for ORIGINAL file
                $originalResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $documentId,
                    filename: $request->input('filename'),
                    contentType: $request->input('content_type'),
                    fileSize: $request->input('file_size', 0)
                );

                $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $originalResult['file_info']['path'];

                // Generate presigned URL for CONVERTED PDF
                $convertedResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'DocumentManagement',
                    moduleNameId: (string) $documentId,
                    filename: $request->input('converted_filename'),
                    contentType: 'application/pdf',
                    fileSize: $request->input('converted_file_size', 0)
                );

                $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $convertedResult['file_info']['path'];

                // Create new DocumentVersion (PDF + Original)
                DocumentVersion::create([
                    'DocumentManagementID' => $documentId,
                    'VersionNo' => $newVersionNo,
                    'DocumentPath' => $convertedResult['file_info']['path'], // PDF for display
                    'DocumentUrl' => $convertedStaticUrl,
                    'DocumentOriginalPath' => $originalResult['file_info']['path'], // Original for download
                    'DocumentOriginalUrl' => $originalStaticUrl,
                    'AtTimeStamp' => $timestamp,
                ]);

                $responseData = [
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'previous_version' => $currentVersion,
                    'current_version' => $newVersionNo,
                    'upload_url' => $convertedResult['upload_url'],
                    'file_path' => $convertedResult['file_info']['path'],
                    'filename' => $convertedResult['file_info']['filename'],
                    'expires_in' => $convertedResult['expires_in'],
                    'original_upload_url' => $originalResult['upload_url'],
                    'original_file_path' => $originalResult['file_info']['path'],
                    'original_filename' => $originalResult['file_info']['filename'],
                    'original_expires_in' => $originalResult['expires_in'],

                ];
            }

            // // Generate presigned URL for new version
            // $result = $this->minioService->generatePresignedUploadUrl(
            //     moduleName: 'DocumentManagement',
            //     moduleNameId: (string) $documentId,
            //     filename: $request->input('filename'),
            //     contentType: $request->input('content_type'),
            //     fileSize: $request->input('file_size', 0)
            // );

            // // Create new DocumentVersion
            // $staticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
            //     . '/' . config('filesystems.disks.minio.bucket')
            //     . '/' . $result['file_info']['path'];

            // DocumentVersion::create([
            //     'DocumentManagementID' => $documentId,
            //     'VersionNo' => $newVersionNo,
            //     'DocumentPath' => $result['file_info']['path'],
            //     'DocumentUrl'  => $staticUrl,
            //     'AtTimeStamp' => $timestamp,
            // ]);

            // Update LatestVersionNo and Notes in DocumentManagement
            $document->update([
                'LatestVersionNo' => $newVersionNo,
                'Notes' => $request->input('notes', $document->Notes),
                'OperationCode' => 'U',
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentManagement',
                'ReferenceRecordID' => $documentId,
                'Data' => json_encode([
                    'DocumentName' => $document->DocumentName,
                    'PreviousVersionNo' => $currentVersion,
                    'NewVersionNo' => $newVersionNo,
                    'HasConvertedPDF' => $hasConvertedPdf,
                    'DocumentPath' => !$hasConvertedPdf ? $originalResult['file_info']['path'] : $convertedResult['file_info']['path'],
                    'DocumentOriginalPath' =>   $originalResult['file_info']['path'],
                ]),
                'Note' => "Document updated - version {$newVersionNo} created"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $responseData
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
     * Get document with all versions
     */
    public function getDocument(Request $request, $documentId)
    {
        try {
            $document = DocumentManagement::findOrFail($documentId);

            $documentType = $document->DocumentType;

            // Query ulang dengan eager load
            $document = DocumentManagement::with([
                'documentVersions' => fn($q) => $q->orderBy('VersionNo', 'desc'),
                'documentRoles.organization',
                'organization',
                'user',
            ])
                ->when($documentType === 'RACI', function ($q) {
                    $q->with('raciActivities.position.organization');
                })
                ->findOrFail($documentId);
            // $document = DocumentManagement::with([
            //     'documentVersions' => function ($query) {
            //         $query->orderBy('VersionNo', 'desc');
            //     },
            //     'raciActivities.position.organization', // PIC is Position
            //     'documentRoles.organization',
            //     'organization',
            //     'user'
            // ])->when($documentType === 'RACI', function ($q) {
            //     $q->with('raciActivities.position.organization');
            // })
            //     ->findOrFail($documentId);

            return response()->json([
                'success' => true,
                'message' => 'Document retrieved successfully',
                'data' => $document
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get document version URL for view/download
     */
    public function getVersionUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:DocumentManagement,DocumentManagementID',
            'version_no' => 'nullable|integer|min:1',
            'force_download' => 'nullable|boolean',
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $documentId = $request->input('document_id');
            $organizationId = $request->input('organization_id');
            $forceDownload = $request->input('force_download', false);
            $document = DocumentManagement::findOrFail($documentId);

            $document = DocumentManagement::with('documentRoles')->findOrFail($documentId);

            // Check if user's organization has access
            $isOwner = $document->OrganizationID == $organizationId;
            $hasAccess = $isOwner;
            $canDownload = $isOwner;
            $canView = true; // Default can view

            if (!$isOwner) {
                // Check DocumentRole
                $accessRole = $document->documentRoles
                    ->where('OrganizationID', $organizationId)
                    ->first();

                if ($accessRole) {
                    $hasAccess = true;
                    $canDownload = $accessRole->IsDownload;
                    $canView = true; // If has role, can view
                }
            }

            // Check permission
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this document'
                ], 403);
            }

            // Check download permission
            if ($forceDownload && !$canDownload) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have download permission for this document'
                ], 403);
            }


            // If version_no not specified, use latest version
            $versionNo = $request->input('version_no', $document->LatestVersionNo);

            $version = DocumentVersion::version($documentId, $versionNo)->first();

            if (!$version) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document version not found'
                ], 404);
            }

            // Check if file exists in MinIO
            if (!$this->minioService->fileExists($version->DocumentPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found in storage'
                ], 404);
            }

            // Generate appropriate URL
            if ($forceDownload) {
                $url = $this->minioService->generatePresignedDownloadUrl(
                    path: $version->DocumentPath,
                    forceDownload: true,
                    downloadFilename: $document->DocumentName
                );
            } else {
                $url = $this->minioService->generatePresignedViewUrl(
                    path: $version->DocumentPath
                );
            }

            // Log access
            $timestamp = Carbon::now()->timestamp;
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'R',
                'ReferenceTable' => 'DocumentManagement',
                'ReferenceRecordID' => $documentId,
                'Data' => json_encode([
                    'DocumentName' => $document->DocumentName,
                    'VersionNo' => $versionNo,
                    'DocumentPath' => $version->DocumentPath,
                    'ForceDownload' => $forceDownload,
                    'OrganizationID' => $organizationId,
                    'IsOwner' => $isOwner,
                    'CanDownload' => $canDownload,
                ]),
                'Note' => $forceDownload ? 'Document version downloaded' : 'Document version viewed'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'URL generated successfully',
                'data' => [
                    'url' => $url,
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'version_no' => $versionNo,
                    'document_path' => $version->DocumentPath,
                    'force_download' => $forceDownload,
                    'access_info' => [
                        'is_owner' => $isOwner,
                        'can_download' => $canDownload,
                        'can_comment' => $isOwner || ($accessRole->IsComment ?? false),
                    ]
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
     * List documents by organization
     */
    public function listByOrganization(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            'document_type' => 'nullable|string|max:255',
            'document_name' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
        ]);
        // dd($request->auth_user);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        $organizationId = $request->input('organization_id');
        try {
            // ============================
            // 1. User Login
            // ============================
            $current_user = $request->auth_user;
            $isAdmin = $current_user->IsAdministrator;

            // Ambil employee org jika bukan admin
            $employeeOrgId = null;
            if (!$isAdmin) {
                $employee = Employee::findOrFail($current_user->UserID);
                $employeeOrgId = $employee->OrganizationID;
            }
            // $query = DocumentManagement::where(function ($q) use ($organizationId, $employeeOrgId) {
            //     // Owner organization
            //     $q->where('OrganizationID', $organizationId)
            //         // OR has access via DocumentRole
            //         ->orWhereHas('documentRoles', function ($roleQuery) use ($employeeOrgId) {
            //             $roleQuery->where('OrganizationID', $employeeOrgId);
            //         });
            // })
            //     ->with(['latestVersion', 'organization', 'user', 'documentRoles']);

            $query = DocumentManagement::where('OrganizationID', $organizationId)
                ->with([
                    'latestVersion',
                    'organization',
                    'user',
                    'documentRoles',
                    'raciActivities' => function ($q) {
                        $q->with([
                            'position' => function ($posQuery) {
                                $posQuery->with([
                                    'organization',
                                    'positionLevel'
                                ]);
                            }
                        ]);
                    }
                ]);

            if (!$isAdmin) {
                $query->where(function ($q) use ($employeeOrgId) {
                    $q->where('OrganizationID', $employeeOrgId)
                        ->orWhereHas('documentRoles', function ($roleQuery) use ($employeeOrgId) {
                            $roleQuery->where('OrganizationID', $employeeOrgId);
                        });
                });
            }

            if ($request->has('document_type') && !empty($request->input('document_type'))) {
                $query->where('DocumentType', $request->input('document_type'));
            }

            // Filter by DocumentName (exact match)
            if ($request->has('document_name') && !empty($request->input('document_name'))) {
                $query->where('DocumentName', 'LIKE', '%' . $request->input('document_name') . '%');
            }

            // Global search (search in DocumentName, DocumentType, Description)
            if ($request->has('search') && !empty($request->input('search'))) {
                $searchTerm = $request->input('search');
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('DocumentName', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('DocumentType', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('Description', 'LIKE', '%' . $searchTerm . '%');
                });
            }
            $documents = $query->orderBy('AtTimeStamp', 'desc')->get();
            // Add access info for each document
            $documents->transform(function ($document) use ($isAdmin, $employeeOrgId) {

                $isOwner = (!$isAdmin && $document->OrganizationID == $employeeOrgId);

                $accessRole = null;
                if (!$isAdmin && !$isOwner) {
                    $accessRole = $document->documentRoles
                        ->where('OrganizationID', $employeeOrgId)
                        ->first();
                }

                $latest = $document->latestVersion->first();

                $document->latest_file = [
                    'version_no' => $latest->VersionNo ?? null,
                    'file_path'  => $latest->DocumentPath ?? null,
                    'file_url'   => $latest->DocumentUrl ?? null,
                ];

                $document->access_info = [
                    'is_admin'    => $isAdmin,
                    'is_owner'    => $isOwner,
                    'can_download' => $isAdmin ? true : ($accessRole->IsDownload ?? false),
                    'can_comment' => $isAdmin ? true : ($accessRole->IsComment ?? false),
                ];

                return $document;
            });

            return response()->json([
                'success' => true,
                'message' => 'Documents retrieved successfully',
                'data' => $documents,
                'total' => $documents->count()
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
     * Update document metadata (not file)
     */
    public function updateDocumentInfo(Request $request, $documentId)
    {
        $validator = Validator::make($request->all(), [
            'document_name' => 'nullable|string|max:255',
            'document_type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
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
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentManagement::findOrFail($documentId);
            $oldData = $document->toArray();

            $updateData = array_filter([
                'DocumentName' => $request->input('document_name'),
                'DocumentType' => $request->input('document_type'),
                'Description' => $request->input('description'),
                'Notes' => $request->input('notes'),
            ], function ($value) {
                return !is_null($value);
            });

            $document->update($updateData);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentManagement',
                'ReferenceRecordID' => $documentId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $updateData,
                ]),
                'Note' => 'Document metadata updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document information updated successfully',
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

    public function viewDocument(Request $request, $documentId)
    {
        // Validasi
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            'version_no' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $organizationId = $request->input('organization_id');
            $versionNo = $request->input('version_no');

            // Ambil document & cek akses
            $document = DocumentManagement::with('documentRoles')
                ->findOrFail($documentId);

            // Cek akses
            $isOwner = $document->OrganizationID == $organizationId;
            $accessRole = null;
            $hasAccess = $isOwner;

            if (!$isOwner) {
                $accessRole = $document->documentRoles
                    ->where('OrganizationID', $organizationId)
                    ->first();
                if ($accessRole) {
                    $hasAccess = true;
                }
            }

            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'No access to this document'
                ], 403);
            }

            // Tentukan version_no → default latest
            $versionNo = $versionNo ?? $document->LatestVersionNo;

            $version = DocumentVersion::version($documentId, $versionNo)->first();

            if (!$version) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document version not found'
                ], 404);
            }

            // Generate presigned VIEW URL
            $url = $this->minioService->generatePresignedViewUrl(
                path: $version->DocumentPath
            );

            return response()->json([
                'success' => true,
                'message' => 'Document view URL generated',
                'data' => [
                    'url' => $url,
                    'document_id' => $documentId,
                    'version_no' => $versionNo,
                    'file_path' => $version->DocumentPath,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate document view URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all versions of a document
     */
    public function getAllVersions(Request $request, $documentId)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $organizationId = $request->input('organization_id');

            // Check if document exists
            $document = DocumentManagement::with('documentRoles')
                ->findOrFail($documentId);

            // Check access permission
            $isOwner = $document->OrganizationID == $organizationId;
            $hasAccess = $isOwner;

            if (!$isOwner) {
                $accessRole = $document->documentRoles
                    ->where('OrganizationID', $organizationId)
                    ->first();

                if ($accessRole) {
                    $hasAccess = true;
                }
            }

            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this document'
                ], 403);
            }

            // Get all versions ordered by version number descending (latest first)
            $versions = DocumentVersion::where('DocumentManagementID', $documentId)
                ->orderBy('VersionNo', 'desc')
                ->get()
                ->map(function ($version) {
                    return [
                        'version_no' => $version->VersionNo,
                        'document_path' => $version->DocumentPath,
                        'document_url' => $version->DocumentUrl,
                        'created_at' => $version->AtTimeStamp,
                        'created_at_formatted' => Carbon::createFromTimestamp($version->AtTimeStamp)
                            ->format('Y-m-d H:i:s'),
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Document versions retrieved successfully',
                'data' => [
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'document_type' => $document->DocumentType,
                    'latest_version_no' => $document->LatestVersionNo,
                    'total_versions' => $versions->count(),
                    'versions' => $versions,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve document versions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add RACI activities to existing document
     */
    public function addRaciDocument(Request $request, $documentId)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            'raci_activities' => 'required|array|min:1',
            'raci_activities.*.activity' => 'required|string|max:255',
            'raci_activities.*.pic' => 'required|integer|exists:Position,PositionID',
            'raci_activities.*.status' => 'required|in:Informed,Accountable,Consulted',
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
            $authUserId = $request->auth_user_id;
            $organizationId = $request->input('organization_id');
            $timestamp = Carbon::now()->timestamp;

            // Check if document exists
            $document = DocumentManagement::with('documentRoles')
                ->findOrFail($documentId);

            // Check access permission (only owner can add RACI)
            $isOwner = $document->OrganizationID == $organizationId;

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only document owner can add RACI activities'
                ], 403);
            }

            // Get all allowed organization IDs (main organization + DocumentRoles)
            $allowedOrgIds = [$document->OrganizationID];

            $documentRoleOrgIds = $document->documentRoles
                ->pluck('OrganizationID')
                ->toArray();

            $allowedOrgIds = array_merge($allowedOrgIds, $documentRoleOrgIds);
            $allowedOrgIds = array_unique($allowedOrgIds);

            // Validate each RACI activity's PIC
            $raciActivities = $request->input('raci_activities');
            $invalidPositions = [];

            foreach ($raciActivities as $index => $raciData) {
                $positionId = $raciData['pic'];

                // Check if Position exists and get its OrganizationID
                $position = Position::with('organization')
                    ->find($positionId);

                if (!$position) {
                    $invalidPositions[] = [
                        'index' => $index,
                        'position_id' => $positionId,
                        'reason' => 'Position not found'
                    ];
                    continue;
                }

                // Check if Position's OrganizationID is in allowed organizations
                if (!in_array($position->OrganizationID, $allowedOrgIds)) {
                    $invalidPositions[] = [
                        'index' => $index,
                        'position_id' => $positionId,
                        'position_name' => $position->PositionName ?? 'N/A',
                        'position_organization_id' => $position->OrganizationID,
                        'position_organization_name' => $position->organization->OrganizationName ?? 'N/A',
                        'reason' => 'Position organization not in document access list',
                        'allowed_organizations' => $allowedOrgIds
                    ];
                }
            }

            // If there are invalid positions, return error
            if (!empty($invalidPositions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some positions are not from organizations with access to this document',
                    'errors' => [
                        'invalid_positions' => $invalidPositions,
                        'allowed_organization_ids' => $allowedOrgIds
                    ]
                ], 422);
            }

            // Delete existing RACI activities for this document (optional: if you want to replace)
            // RaciActivity::where('DocumentManagementID', $documentId)->delete();

            // Create new RACI activities
            $createdRaciActivities = [];

            foreach ($raciActivities as $raciData) {
                $raciActivity = RaciActivity::create([
                    'RaciActivityID' => Carbon::now()->timestamp . random_numbersu(5),
                    'DocumentManagementID' => $documentId,
                    'Activity' => $raciData['activity'],
                    'PIC' => $raciData['pic'],
                    'Status' => $raciData['status'],
                ]);

                $createdRaciActivities[] = $raciActivity;
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'RaciActivity',
                'ReferenceRecordID' => $documentId,
                'Data' => json_encode([
                    'DocumentManagementID' => $documentId,
                    'DocumentName' => $document->DocumentName,
                    'RaciActivitiesCount' => count($createdRaciActivities),
                    'RaciActivities' => $raciActivities,
                    'AllowedOrganizations' => $allowedOrgIds,
                ]),
                'Note' => 'RACI activities added to document'
            ]);

            DB::commit();

            // Load position details for response
            $raciActivitiesWithDetails = RaciActivity::with('position.organization')
                ->whereIn('RaciActivityID', collect($createdRaciActivities)->pluck('RaciActivityID'))
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'RACI activities added successfully',
                'data' => [
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'total_raci_activities' => count($createdRaciActivities),
                    'allowed_organizations' => $allowedOrgIds,
                    'raci_activities' => $raciActivitiesWithDetails,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add RACI activities',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
