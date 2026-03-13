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
            'filename' => 'required|string|max:255|regex:/\\.pdf$/i',
            'content_type' => 'required|string|in:application/pdf',
            'file_size' => 'nullable|integer|min:1',

            // RACI Activities (only if DocumentType = 'RACI')
            'raci_activities' => 'nullable|array',
            'raci_activities.*.activity' => 'required_with:raci_activities|string|max:255',
            'raci_activities.*.pic' => 'required_with:raci_activities|integer|exists:Position,PositionID',
            'raci_activities.*.status' => 'required|in:' . implode(',', RaciActivity::getStatuses()),

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

        if ($request->boolean('has_converted_pdf') || $request->filled('converted_filename')) {
            return response()->json([
                'success' => false,
                'message' => 'Only PDF file type is allowed',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;
            $documentType = $request->input('document_type');

            // Validate RACI activities only required if DocumentType = 'RACI'
            if ($documentType === 'RACI' && !$request->has('raci_activities')) {
                throw new \Exception('RACI activities are required for DocumentType = RACI');
            }

            // Create DocumentManagement
            $document = DocumentManagement::create([
                'DocumentManagementID' => DocumentManagement::generateDailyDocumentManagementId(),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'DocumentName' => $request->input('document_name'),
                'DocumentType' => $documentType,
                'Description' => $request->input('description'),
                'Notes' => $request->input('notes'),
                'OrganizationID' => $request->input('organization_id'),
                'LatestVersionNo' => 1,
                'IsActive' => true,
            ]);

            // PDF only upload
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

            DocumentVersion::create([
                'DocumentManagementID' => $document->DocumentManagementID,
                'VersionNo' => 1,
                'DocumentPath' => $originalResult['file_info']['path'],
                'DocumentUrl' => $pdfStaticUrl,
                'DocumentOriginalPath' => null,
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
                    'HasConvertedPDF' => false,
                    'DocumentPath' => $originalResult['file_info']['path'],
                    'DocumentOriginalPath' => null,
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
            'filename' => 'required|string|max:255|regex:/\\.pdf$/i',
            'content_type' => 'required|string|in:application/pdf',
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

        if ($request->boolean('has_converted_pdf') || $request->filled('converted_filename')) {
            return response()->json([
                'success' => false,
                'message' => 'Only PDF file type is allowed',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $authUserId = $request->auth_user_id;
            // $authUserId = $request->user()->UserID ?? $request->user()->id;
            $timestamp = Carbon::now()->timestamp;

            // Check if document exists
            $document = DocumentManagement::active()->findOrFail($documentId);

            // Get current latest version
            $currentVersion = $document->LatestVersionNo ?? 1;
            $newVersionNo = $currentVersion + 1;

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
                    'HasConvertedPDF' => false,
                    'DocumentPath' => $originalResult['file_info']['path'],
                    'DocumentOriginalPath' => null,
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
            $document = DocumentManagement::active()->findOrFail($documentId);

            $documentType = $document->DocumentType;

            // Query ulang dengan eager load
            $document = DocumentManagement::active()->with([
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
            $document = DocumentManagement::active()->findOrFail($documentId);

            $document = DocumentManagement::active()->with('documentRoles')->findOrFail($documentId);

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
            'organization_id' => 'nullable|integer|exists:Organization,OrganizationID',
            'document_type'   => 'nullable|string|max:255',
            'document_name'   => 'nullable|string|max:255',
            'search'          => 'nullable|string|max:255',
            'is_active'       => 'nullable|boolean',
            'page'            => 'nullable|integer|min:1',
            'per_page'        => 'nullable|integer|min:1|max:100',
            'owned_page'      => 'nullable|integer|min:1',
            'owned_per_page'  => 'nullable|integer|min:1|max:100',
            'assigned_page'   => 'nullable|integer|min:1',
            'assigned_per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $currentUser = $request->auth_user;
            $isAdmin = (bool) $currentUser->IsAdministrator;

            $employeeOrgId = null;
            if (!$isAdmin) {
                $employee = Employee::findOrFail($currentUser->UserID);
                $employeeOrgId = $employee->OrganizationID;
            }

            if (!$isAdmin && $request->filled('organization_id') && (int) $request->input('organization_id') !== (int) $employeeOrgId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non-admin can only query their own organization',
                ], 403);
            }

            $targetOrgId = $isAdmin
                ? ($request->filled('organization_id') ? (int) $request->input('organization_id') : null)
                : (int) $employeeOrgId;

            $withRelations = [
                'latestVersion',
                'organization',
                'user',
                'documentRoles',
                'raciActivities.position.organization',
                'raciActivities.position.positionLevel',
            ];

            $applyCommonFilters = function ($query) use ($request) {
                if ($request->filled('document_type')) {
                    $query->where('DocumentType', $request->input('document_type'));
                }

                if ($request->filled('document_name')) {
                    $query->where('DocumentName', 'LIKE', '%' . $request->input('document_name') . '%');
                }

                if ($request->filled('search')) {
                    $search = $request->input('search');
                    $query->where(function ($q) use ($search) {
                        $q->where('DocumentName', 'LIKE', "%{$search}%")
                            ->orWhere('DocumentType', 'LIKE', "%{$search}%")
                            ->orWhere('Description', 'LIKE', "%{$search}%");
                    });
                }

                if ($request->has('is_active')) {
                    $query->where('IsActive', $request->boolean('is_active'));
                }

                return $query;
            };

            $ownedQuery = $applyCommonFilters(DocumentManagement::query()->with($withRelations));
            if (!is_null($targetOrgId)) {
                $ownedQuery->where('OrganizationID', $targetOrgId);
            }

            $assignedQuery = $applyCommonFilters(DocumentManagement::query()->with($withRelations));
            if (!is_null($targetOrgId)) {
                $assignedQuery
                    ->where('OrganizationID', '!=', $targetOrgId)
                    ->whereHas('documentRoles', function ($q) use ($targetOrgId) {
                        $q->where('OrganizationID', $targetOrgId);
                    });
            } else {
                $assignedQuery->whereRaw('1 = 0');
            }

            $defaultPerPage = (int) $request->input('per_page', 10);
            $defaultPage = (int) $request->input('page', 1);
            $ownedPerPage = (int) $request->input('owned_per_page', $defaultPerPage);
            $assignedPerPage = (int) $request->input('assigned_per_page', $defaultPerPage);
            $ownedPage = (int) $request->input('owned_page', $defaultPage);
            $assignedPage = (int) $request->input('assigned_page', $defaultPage);

            $ownedDocuments = $ownedQuery
                ->orderBy('AtTimeStamp', 'desc')
                ->paginate($ownedPerPage, ['*'], 'owned_page', $ownedPage);

            $assignedDocuments = $assignedQuery
                ->orderBy('AtTimeStamp', 'desc')
                ->paginate($assignedPerPage, ['*'], 'assigned_page', $assignedPage);

            $transformDocuments = function ($paginator) use ($isAdmin, $targetOrgId) {
                $paginator->getCollection()->transform(function ($document) use ($isAdmin, $targetOrgId) {
                    $isOwner = !is_null($targetOrgId) && (int) $document->OrganizationID === (int) $targetOrgId;

                    $accessRole = null;
                    if (!is_null($targetOrgId) && !$isOwner) {
                        $accessRole = $document->documentRoles
                            ->where('OrganizationID', $targetOrgId)
                            ->first();
                    }

                    $latest = optional($document->latestVersion)->first();

                    $document->latest_file = [
                        'version_no' => $latest->VersionNo ?? null,
                        'file_path'  => $latest->DocumentPath ?? null,
                        'file_url'   => $latest->DocumentUrl ?? null,
                    ];

                    $document->access_info = [
                        'is_admin' => $isAdmin,
                        'reference_organization_id' => $targetOrgId,
                        'is_owner' => $isOwner,
                        'can_download' => $isAdmin ? true : ($isOwner ? true : ($accessRole->IsDownload ?? false)),
                        'can_comment' => $isAdmin ? true : ($isOwner ? true : ($accessRole->IsComment ?? false)),
                    ];

                    return $document;
                });

                return $paginator;
            };

            $ownedDocuments = $transformDocuments($ownedDocuments);
            $assignedDocuments = $transformDocuments($assignedDocuments);

            return response()->json([
                'success' => true,
                'message' => 'Documents retrieved successfully',
                'data' => [
                    'owned_documents' => $ownedDocuments->items(),
                    'assigned_documents' => $assignedDocuments->items(),
                ],
                'meta' => [
                    'reference_organization_id' => $targetOrgId,
                    'owned' => [
                        'current_page' => $ownedDocuments->currentPage(),
                        'per_page' => $ownedDocuments->perPage(),
                        'total' => $ownedDocuments->total(),
                        'last_page' => $ownedDocuments->lastPage(),
                    ],
                    'assigned' => [
                        'current_page' => $assignedDocuments->currentPage(),
                        'per_page' => $assignedDocuments->perPage(),
                        'total' => $assignedDocuments->total(),
                        'last_page' => $assignedDocuments->lastPage(),
                    ],
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve documents',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List documents that have RACI activities by organization
     * DocumentType is not restricted (can be any type).
     */
    public function listRaciByOrganization(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'nullable|integer|exists:Organization,OrganizationID',
            'document_name'   => 'nullable|string|max:255',
            'search'          => 'nullable|string|max:255',
            'is_active'       => 'nullable|boolean',
            'page'            => 'nullable|integer|min:1',
            'per_page'        => 'nullable|integer|min:1|max:100',
            'owned_page'      => 'nullable|integer|min:1',
            'owned_per_page'  => 'nullable|integer|min:1|max:100',
            'assigned_page'   => 'nullable|integer|min:1',
            'assigned_per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $currentUser = $request->auth_user;
            $isAdmin = (bool) $currentUser->IsAdministrator;

            $employeeOrgId = null;
            if (!$isAdmin) {
                $employee = Employee::findOrFail($currentUser->UserID);
                $employeeOrgId = $employee->OrganizationID;
            }

            if (!$isAdmin && $request->filled('organization_id') && (int) $request->input('organization_id') !== (int) $employeeOrgId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non-admin can only query their own organization',
                ], 403);
            }

            $targetOrgId = $isAdmin
                ? ($request->filled('organization_id') ? (int) $request->input('organization_id') : null)
                : (int) $employeeOrgId;

            $withRelations = [
                'latestVersion',
                'organization',
                'user',
                'documentRoles',
                'raciActivities.position.organization',
                'raciActivities.position.positionLevel',
            ];

            $applyCommonFilters = function ($query) use ($request) {
                $query->whereHas('raciActivities');

                if ($request->filled('document_name')) {
                    $query->where('DocumentName', 'LIKE', '%' . $request->input('document_name') . '%');
                }

                if ($request->filled('search')) {
                    $search = $request->input('search');
                    $query->where(function ($q) use ($search) {
                        $q->where('DocumentName', 'LIKE', "%{$search}%")
                            ->orWhere('DocumentType', 'LIKE', "%{$search}%")
                            ->orWhere('Description', 'LIKE', "%{$search}%");
                    });
                }

                if ($request->has('is_active')) {
                    $query->where('IsActive', $request->boolean('is_active'));
                }

                return $query;
            };

            $ownedQuery = $applyCommonFilters(DocumentManagement::query()->with($withRelations));
            if (!is_null($targetOrgId)) {
                $ownedQuery->where('OrganizationID', $targetOrgId);
            }

            $assignedQuery = $applyCommonFilters(DocumentManagement::query()->with($withRelations));
            if (!is_null($targetOrgId)) {
                $assignedQuery
                    ->where('OrganizationID', '!=', $targetOrgId)
                    ->whereHas('documentRoles', function ($q) use ($targetOrgId) {
                        $q->where('OrganizationID', $targetOrgId);
                    });
            } else {
                $assignedQuery->whereRaw('1 = 0');
            }

            $defaultPerPage = (int) $request->input('per_page', 10);
            $defaultPage = (int) $request->input('page', 1);
            $ownedPerPage = (int) $request->input('owned_per_page', $defaultPerPage);
            $assignedPerPage = (int) $request->input('assigned_per_page', $defaultPerPage);
            $ownedPage = (int) $request->input('owned_page', $defaultPage);
            $assignedPage = (int) $request->input('assigned_page', $defaultPage);

            $ownedDocuments = $ownedQuery
                ->orderBy('AtTimeStamp', 'desc')
                ->paginate($ownedPerPage, ['*'], 'owned_page', $ownedPage);

            $assignedDocuments = $assignedQuery
                ->orderBy('AtTimeStamp', 'desc')
                ->paginate($assignedPerPage, ['*'], 'assigned_page', $assignedPage);

            $transformDocuments = function ($paginator) use ($isAdmin, $targetOrgId) {
                $paginator->getCollection()->transform(function ($document) use ($isAdmin, $targetOrgId) {
                    $isOwner = !is_null($targetOrgId) && (int) $document->OrganizationID === (int) $targetOrgId;

                    $accessRole = null;
                    if (!is_null($targetOrgId) && !$isOwner) {
                        $accessRole = $document->documentRoles
                            ->where('OrganizationID', $targetOrgId)
                            ->first();
                    }

                    $latest = optional($document->latestVersion)->first();

                    $document->latest_file = [
                        'version_no' => $latest->VersionNo ?? null,
                        'file_path'  => $latest->DocumentPath ?? null,
                        'file_url'   => $latest->DocumentUrl ?? null,
                    ];

                    $document->access_info = [
                        'is_admin' => $isAdmin,
                        'reference_organization_id' => $targetOrgId,
                        'is_owner' => $isOwner,
                        'can_download' => $isAdmin ? true : ($isOwner ? true : ($accessRole->IsDownload ?? false)),
                        'can_comment' => $isAdmin ? true : ($isOwner ? true : ($accessRole->IsComment ?? false)),
                    ];

                    return $document;
                });

                return $paginator;
            };

            $ownedDocuments = $transformDocuments($ownedDocuments);
            $assignedDocuments = $transformDocuments($assignedDocuments);

            return response()->json([
                'success' => true,
                'message' => 'RACI documents retrieved successfully',
                'data' => [
                    'owned_documents' => $ownedDocuments->items(),
                    'assigned_documents' => $assignedDocuments->items(),
                ],
                'meta' => [
                    'reference_organization_id' => $targetOrgId,
                    'owned' => [
                        'current_page' => $ownedDocuments->currentPage(),
                        'per_page' => $ownedDocuments->perPage(),
                        'total' => $ownedDocuments->total(),
                        'last_page' => $ownedDocuments->lastPage(),
                    ],
                    'assigned' => [
                        'current_page' => $assignedDocuments->currentPage(),
                        'per_page' => $assignedDocuments->perPage(),
                        'total' => $assignedDocuments->total(),
                        'last_page' => $assignedDocuments->lastPage(),
                    ],
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve RACI documents',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function inactiveDocument(Request $request, $documentId)
    {
        DB::beginTransaction();

        try {
            $authUser = $request->auth_user;
            $authUserId = $request->auth_user_id;
            $isAdmin = (bool) ($authUser->IsAdministrator ?? false);
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentManagement::findOrFail($documentId);

            if (!$isAdmin) {
                $employee = Employee::findOrFail($authUserId);
                $employeeOrgId = (int) $employee->OrganizationID;

                if ((int) $document->OrganizationID !== $employeeOrgId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only document owner organization can deactivate this document',
                    ], 403);
                }
            }

            $document->update([
                'IsActive' => false,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ]);

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentManagement',
                'ReferenceRecordID' => $documentId,
                'Data' => json_encode([
                    'DocumentManagementID' => $documentId,
                    'DocumentName' => $document->DocumentName,
                    'IsActive' => false,
                ]),
                'Note' => 'Document set to inactive'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document deactivated successfully',
                'data' => [
                    'document_id' => (int) $documentId,
                    'is_active' => false,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function oldlistByOrganization(Request $request)
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

            $query = DocumentManagement::active()->where('OrganizationID', $organizationId)
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
            'is_active' => 'nullable|boolean',

                    // Document Roles update
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

            $document = DocumentManagement::active()->with('documentRoles')->findOrFail($documentId);
            $oldData = $document->toArray();

            $updateData = array_filter([
                'DocumentName' => $request->input('document_name'),
                'DocumentType' => $request->input('document_type'),
                'Description' => $request->input('description'),
                'Notes' => $request->input('notes'),
                'IsActive' => $request->input('is_active'),
            ], function ($value) {
                return !is_null($value);
            });

            $document->update($updateData);

            // Update DocumentRole if access_organization_ids is provided
            if ($request->has('access_organization_ids')) {
                $accessOrgIds = array_values(array_unique($request->input('access_organization_ids', [])));
                $ownerOrgId = $document->OrganizationID;

                // VALIDASI: Owner organization HARUS ada dalam access_organization_ids
                if (!in_array($ownerOrgId, $accessOrgIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Owner organization cannot be removed from document access',
                        'errors' => [
                            'access_organization_ids' => [
                                "Owner organization (ID: {$ownerOrgId}) must be included in access list"
                            ]
                        ],
                        'owner_organization_id' => $ownerOrgId
                    ], 422);
                }

                $oldAllowedOrgIds = $document->documentRoles
                    ->pluck('OrganizationID')
                    ->push($ownerOrgId)
                    ->unique()
                    ->values()
                    ->toArray();

                $removedOrgIds = array_values(array_diff($oldAllowedOrgIds, $accessOrgIds));
                $blockedOrgIds = $this->getBlockedRemovedOrganizationIds($documentId, $removedOrgIds);

                if (!empty($blockedOrgIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Not allowed to remove organization(s) because PIC is still registered in document RACI activities',
                        'errors' => [
                            'removed_organization_ids' => $removedOrgIds,
                            'blocked_organization_ids' => $blockedOrgIds,
                        ]
                    ], 400);
                }

                // Backup old DocumentRoles for audit log
                $oldDocumentRoles = $document->documentRoles->map(function ($role) {
                    return [
                        'DocumentRoleID' => $role->DocumentRoleID,
                        'OrganizationID' => $role->OrganizationID,
                        'IsDownload' => $role->IsDownload,
                        'IsComment' => $role->IsComment,
                    ];
                })->toArray();

                // DELETE all existing DocumentRoles
                DocumentRole::where('DocumentManagementID', $documentId)->delete();

                // CREATE new DocumentRoles based on access_organization_ids
                $isDownload = $request->input('is_download', true);
                $isComment = $request->input('is_comment', true);
                $newDocumentRoles = [];

                foreach ($accessOrgIds as $orgId) {
                    $role = DocumentRole::create([
                        'DocumentRoleID' => Carbon::now()->timestamp . random_numbersu(5),
                        'DocumentManagementID' => $documentId,
                        'OrganizationID' => $orgId,
                        'IsDownload' => $isDownload,
                        'IsComment' => $isComment,
                    ]);

                    $newDocumentRoles[] = [
                        'DocumentRoleID' => $role->DocumentRoleID,
                        'OrganizationID' => $role->OrganizationID,
                        'IsDownload' => $role->IsDownload,
                        'IsComment' => $role->IsComment,
                    ];

                    // Small delay to ensure unique DocumentRoleID
                    usleep(1000); // 1ms delay
                }
                // Create audit log for DocumentRole changes
                AuditLog::create([
                    'AuditLogID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'ReferenceTable' => 'DocumentRole',
                    'ReferenceRecordID' => $documentId,
                    'Data' => json_encode([
                        'DocumentManagementID' => $documentId,
                        'DocumentName' => $document->DocumentName,
                        'OldDocumentRoles' => $oldDocumentRoles,
                        'NewDocumentRoles' => $newDocumentRoles,
                    ]),
                    'Note' => 'Document access roles updated'
                ]);
            }


             // Create audit log for document metadata update
        if (!empty($updateData)) {
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5) + 1,
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
        }
            DB::commit();

            $document = DocumentManagement::with([
            'documentRoles.organization',
            'organization',
            'user'
        ])->findOrFail($documentId);

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
                ->active()
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
                ->active()
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
                        'document_original_path' => $version->DocumentOriginalPath ?? null,
                        'document_original_url' => $version->DocumentOriginalUrl ?? null,
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
            'raci_activities.*.status' => 'required|in:' . implode(',', RaciActivity::getStatuses()),
            'access_organization_ids' => 'nullable|array',
            'access_organization_ids.*' => 'integer|exists:Organization,OrganizationID',
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
                ->active()
                ->findOrFail($documentId);

            // Check access permission (only owner can add RACI)
            $isOwner = $document->OrganizationID == $organizationId;

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only document owner can add RACI activities'
                ], 403);
            }

            if ($request->has('access_organization_ids')) {
                $newAccessOrgIds = array_values(array_unique($request->input('access_organization_ids', [])));
                $ownerOrgId = $document->OrganizationID;

                if (!in_array($ownerOrgId, $newAccessOrgIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Owner organization cannot be removed from document access',
                        'errors' => [
                            'access_organization_ids' => [
                                "Owner organization (ID: {$ownerOrgId}) must be included in access list"
                            ]
                        ],
                        'owner_organization_id' => $ownerOrgId
                    ], 422);
                }

                $oldAllowedOrgIds = $document->documentRoles
                    ->pluck('OrganizationID')
                    ->push($ownerOrgId)
                    ->unique()
                    ->values()
                    ->toArray();

                $removedOrgIds = array_values(array_diff($oldAllowedOrgIds, $newAccessOrgIds));
                $blockedOrgIds = $this->getBlockedRemovedOrganizationIds($documentId, $removedOrgIds);

                if (!empty($blockedOrgIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Not allowed to change organization access because PIC from removed organization is still registered in RACI activities',
                        'errors' => [
                            'removed_organization_ids' => $removedOrgIds,
                            'blocked_organization_ids' => $blockedOrgIds,
                        ]
                    ], 400);
                }

                DocumentRole::where('DocumentManagementID', $documentId)->delete();

                foreach ($newAccessOrgIds as $orgId) {
                    DocumentRole::create([
                        'DocumentRoleID' => Carbon::now()->timestamp . random_numbersu(5),
                        'DocumentManagementID' => $documentId,
                        'OrganizationID' => $orgId,
                        'IsDownload' => true,
                        'IsComment' => true,
                    ]);

                    usleep(1000); // 1ms delay
                }

                $document->load('documentRoles');
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

    /**
     * Update/Delete RACI activities in a document.
     * - Update: change PIC, Activity, and/or Status
     * - Delete: remove selected RACI activities
     */
    public function updateRaciActivities(Request $request, $documentId)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            'raci_updates' => 'nullable|array|min:1',
            'raci_updates.*.raci_activity_id' => 'required_with:raci_updates|integer|exists:RaciActivity,RaciActivityID',
            'raci_updates.*.activity' => 'nullable|string|max:255',
            'raci_updates.*.pic' => 'nullable|integer|exists:Position,PositionID',
            'raci_updates.*.status' => 'nullable|in:' . implode(',', RaciActivity::getStatuses()),
            'raci_delete_ids' => 'nullable|array|min:1',
            'raci_delete_ids.*' => 'integer|exists:RaciActivity,RaciActivityID',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$request->filled('raci_updates') && !$request->filled('raci_delete_ids')) {
            return response()->json([
                'success' => false,
                'message' => 'At least one of raci_updates or raci_delete_ids is required'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $organizationId = (int) $request->input('organization_id');
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentManagement::with('documentRoles')
                ->active()
                ->findOrFail($documentId);

            $isOwner = (int) $document->OrganizationID === $organizationId;
            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only document owner can update RACI activities'
                ], 403);
            }

            $allowedOrgIds = [$document->OrganizationID];
            $documentRoleOrgIds = $document->documentRoles
                ->pluck('OrganizationID')
                ->toArray();
            $allowedOrgIds = array_values(array_unique(array_merge($allowedOrgIds, $documentRoleOrgIds)));

            $updatedActivities = [];
            $deletedActivities = [];

            $raciUpdates = $request->input('raci_updates', []);
            foreach ($raciUpdates as $idx => $updateData) {
                $raci = RaciActivity::where('DocumentManagementID', $documentId)
                    ->where('RaciActivityID', $updateData['raci_activity_id'])
                    ->first();

                if (!$raci) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'RACI activity not found in this document',
                        'errors' => [
                            'raci_updates' => [
                                "Item index {$idx} is invalid for this document"
                            ]
                        ]
                    ], 422);
                }

                $payload = [];
                if (array_key_exists('activity', $updateData) && !is_null($updateData['activity'])) {
                    $payload['Activity'] = $updateData['activity'];
                }
                if (array_key_exists('status', $updateData) && !is_null($updateData['status'])) {
                    $payload['Status'] = $updateData['status'];
                }
                if (array_key_exists('pic', $updateData) && !is_null($updateData['pic'])) {
                    $position = Position::with('organization')->find($updateData['pic']);
                    if (!$position) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Position not found',
                            'errors' => [
                                'raci_updates' => [
                                    "PIC on item index {$idx} is invalid"
                                ]
                            ]
                        ], 422);
                    }

                    if (!in_array((int) $position->OrganizationID, $allowedOrgIds, true)) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'PIC organization is not allowed for this document',
                            'errors' => [
                                'raci_updates' => [
                                    "PIC organization on item index {$idx} is outside document access list"
                                ]
                            ],
                            'allowed_organization_ids' => $allowedOrgIds
                        ], 422);
                    }

                    $payload['PIC'] = (int) $updateData['pic'];
                }

                if (empty($payload)) {
                    continue;
                }

                $old = [
                    'RaciActivityID' => $raci->RaciActivityID,
                    'Activity' => $raci->Activity,
                    'PIC' => $raci->PIC,
                    'Status' => $raci->Status,
                ];

                $raci->update($payload);
                $raci->refresh();

                $updatedActivities[] = [
                    'old' => $old,
                    'new' => [
                        'RaciActivityID' => $raci->RaciActivityID,
                        'Activity' => $raci->Activity,
                        'PIC' => $raci->PIC,
                        'Status' => $raci->Status,
                    ],
                ];
            }

            $deleteIds = array_values(array_unique($request->input('raci_delete_ids', [])));
            if (!empty($deleteIds)) {
                $toDelete = RaciActivity::where('DocumentManagementID', $documentId)
                    ->whereIn('RaciActivityID', $deleteIds)
                    ->get();

                if ($toDelete->count() !== count($deleteIds)) {
                    $foundIds = $toDelete->pluck('RaciActivityID')->map(fn($id) => (int) $id)->toArray();
                    $missingIds = array_values(array_diff($deleteIds, $foundIds));

                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Some RACI activities are not part of this document',
                        'errors' => [
                            'missing_raci_activity_ids' => $missingIds
                        ]
                    ], 422);
                }

                $deletedActivities = $toDelete->map(function ($item) {
                    return [
                        'RaciActivityID' => $item->RaciActivityID,
                        'Activity' => $item->Activity,
                        'PIC' => $item->PIC,
                        'Status' => $item->Status,
                    ];
                })->values()->toArray();

                RaciActivity::where('DocumentManagementID', $documentId)
                    ->whereIn('RaciActivityID', $deleteIds)
                    ->delete();
            }

            if (!empty($updatedActivities)) {
                AuditLog::create([
                    'AuditLogID' => $timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'ReferenceTable' => 'RaciActivity',
                    'ReferenceRecordID' => $documentId,
                    'Data' => json_encode([
                        'DocumentManagementID' => $documentId,
                        'DocumentName' => $document->DocumentName,
                        'UpdatedRaciActivities' => $updatedActivities,
                    ]),
                    'Note' => 'RACI activities updated'
                ]);
            }

            if (!empty($deletedActivities)) {
                AuditLog::create([
                    'AuditLogID' => ($timestamp + 1) . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'D',
                    'ReferenceTable' => 'RaciActivity',
                    'ReferenceRecordID' => $documentId,
                    'Data' => json_encode([
                        'DocumentManagementID' => $documentId,
                        'DocumentName' => $document->DocumentName,
                        'DeletedRaciActivities' => $deletedActivities,
                    ]),
                    'Note' => 'RACI activities deleted'
                ]);
            }

            DB::commit();

            $latestRaci = RaciActivity::with('position.organization')
                ->where('DocumentManagementID', $documentId)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'RACI activities updated successfully',
                'data' => [
                    'document_id' => (int) $documentId,
                    'updated_count' => count($updatedActivities),
                    'deleted_count' => count($deletedActivities),
                    'raci_activities' => $latestRaci,
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update RACI activities',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit log details by reference table and record id.
     * Current allowed reference tables: DocumentManagement, RaciActivity.
     */
    public function getAuditLogDetailsByReference(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reference_table' => 'required|string|in:DocumentManagement,RaciActivity,RaciAcitivity',
            'reference_record_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $referenceTable = $request->input('reference_table');
            if ($referenceTable === 'RaciAcitivity') {
                $referenceTable = 'RaciActivity';
            }
            $referenceRecordId = (int) $request->input('reference_record_id');

            $logs = AuditLog::with('user')
                ->where('ReferenceTable', $referenceTable)
                ->where('ReferenceRecordID', $referenceRecordId)
                ->orderBy('AtTimeStamp', 'desc')
                ->get()
                ->map(function ($log) {
                    $decoded = null;
                    if (!empty($log->Data)) {
                        $decoded = json_decode($log->Data, true);
                    }

                    return [
                        'audit_log_id' => $log->AuditLogID,
                        'timestamp' => $log->AtTimeStamp,
                        'timestamp_formatted' => Carbon::createFromTimestamp($log->AtTimeStamp)->format('Y-m-d H:i:s'),
                        'operation_code' => $log->OperationCode,
                        'reference_table' => $log->ReferenceTable,
                        'reference_record_id' => $log->ReferenceRecordID,
                        'note' => $log->Note,
                        'data' => $decoded,
                        'actor' => [
                            'user_id' => optional($log->user)->UserID,
                            'username' => optional($log->user)->Username ?? null,
                            'name' => optional($log->user)->Name ?? null,
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Audit log details retrieved successfully',
                'data' => [
                    'reference_table' => $referenceTable,
                    'reference_record_id' => $referenceRecordId,
                    'total_logs' => $logs->count(),
                    'logs' => $logs,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit log details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getBlockedRemovedOrganizationIds(int $documentId, array $removedOrgIds): array
    {
        if (empty($removedOrgIds)) {
            return [];
        }

        return RaciActivity::query()
            ->join('Position', 'RaciActivity.PIC', '=', 'Position.PositionID')
            ->where('RaciActivity.DocumentManagementID', $documentId)
            ->whereIn('Position.OrganizationID', $removedOrgIds)
            ->distinct()
            ->pluck('Position.OrganizationID')
            ->map(fn($orgId) => (int) $orgId)
            ->values()
            ->toArray();
    }
}
