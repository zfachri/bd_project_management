<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentManagement;
use App\Models\DocumentVersion;
use App\Models\RaciActivity;
use App\Models\DocumentRole;
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
            'document_name' => 'required|string|max:255',
            'document_type' => 'required|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            
            // File upload info
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|max:100',
            'file_size' => 'nullable|integer|min:1',
            
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
            $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;
            $documentType = $request->input('document_type');

            // Validate RACI activities only required if DocumentType = 'RACI'
            if ($documentType === 'RACI' && !$request->has('raci_activities')) {
                throw new \Exception('RACI activities are required for DocumentType = RACI');
            }

            // Create DocumentManagement
            $document = DocumentManagement::create([
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

            // Generate presigned URL for version 1
            $result = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'DocumentManagement',
                moduleNameId: (string) $document->DocumentManagementID,
                filename: $request->input('filename'),
                contentType: $request->input('content_type'),
                fileSize: $request->input('file_size', 0)
            );

            // Create DocumentVersion v1
            DocumentVersion::create([
                'DocumentManagementID' => $document->DocumentManagementID,
                'VersionNo' => 1,
                'DocumentPath' => $result['file_info']['path'],
                'AtTimeStamp' => $timestamp,
            ]);

            // Insert DocumentRole for owner organization (OrganizationID from DocumentManagement)
            DocumentRole::create([
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
                    'DocumentName' => $document->DocumentName,
                    'DocumentType' => $document->DocumentType,
                    'OrganizationID' => $document->OrganizationID,
                    'VersionNo' => 1,
                    'DocumentPath' => $result['file_info']['path'],
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
                'data' => [
                    'document_id' => $document->DocumentManagementID,
                    'document_name' => $document->DocumentName,
                    'document_type' => $document->DocumentType,
                    'version_no' => 1,
                    'upload_url' => $result['upload_url'],
                    'file_path' => $result['file_info']['path'],
                    'filename' => $result['file_info']['filename'],
                    'expires_in' => $result['expires_in'],
                ]
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
            $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;

            // Check if document exists
            $document = DocumentManagement::findOrFail($documentId);
            
            // Get current latest version
            $currentVersion = $document->LatestVersionNo ?? 1;
            $newVersionNo = $currentVersion + 1;

            // Generate presigned URL for new version
            $result = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'DocumentManagement',
                moduleNameId: (string) $documentId,
                filename: $request->input('filename'),
                contentType: $request->input('content_type'),
                fileSize: $request->input('file_size', 0)
            );

            // Create new DocumentVersion
            DocumentVersion::create([
                'DocumentManagementID' => $documentId,
                'VersionNo' => $newVersionNo,
                'DocumentPath' => $result['file_info']['path'],
                'AtTimeStamp' => $timestamp,
            ]);

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
                    'DocumentPath' => $result['file_info']['path'],
                ]),
                'Note' => "Document updated - version {$newVersionNo} created"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => [
                    'document_id' => $documentId,
                    'document_name' => $document->DocumentName,
                    'previous_version' => $currentVersion,
                    'current_version' => $newVersionNo,
                    'upload_url' => $result['upload_url'],
                    'file_path' => $result['file_info']['path'],
                    'filename' => $result['file_info']['filename'],
                    'expires_in' => $result['expires_in'],
                ]
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
            $document = DocumentManagement::with([
                'documentVersions' => function($query) {
                    $query->orderBy('VersionNo', 'desc');
                },
                'raciActivities.position.organization', // PIC is Position
                'documentRoles.organization',
                'organization',
                'user'
            ])->findOrFail($documentId);

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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $authUserId = $request->user()->UserID ?? $request->user()->id;
            $documentId = $request->input('document_id');
            $document = DocumentManagement::findOrFail($documentId);
            
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

            $forceDownload = $request->input('force_download', false);

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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $documents = DocumentManagement::byOrganization($request->input('organization_id'))
                ->with(['latestVersion', 'organization', 'user'])
                ->orderBy('AtTimeStamp', 'desc')
                ->get();

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
            $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentManagement::findOrFail($documentId);
            $oldData = $document->toArray();

            $updateData = array_filter([
                'DocumentName' => $request->input('document_name'),
                'DocumentType' => $request->input('document_type'),
                'Description' => $request->input('description'),
                'Notes' => $request->input('notes'),
            ], function($value) {
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
}