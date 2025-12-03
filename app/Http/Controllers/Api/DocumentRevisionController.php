<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentManagement;
use App\Models\DocumentRevision;
use App\Models\DocumentRole;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DocumentRevisionController extends Controller
{
    /**
     * Get user's organization ID through employee relation
     */
    private function getUserOrganizationId($userId)
    {
        $employee = Employee::where('EmployeeID', $userId)->first();
        return $employee ? $employee->OrganizationID : null;
    }

    /**
     * Check if user has access to document based on DocumentRole
     */
    private function userHasDocumentAccess($documentManagementId, $userId)
    {
        $user = User::find($userId);
        
        // Admin always has access
        if ($user->IsAdministrator) {
            return true;
        }

        // Get user's organization ID
        $userOrganizationId = $this->getUserOrganizationId($userId);
        if (!$userOrganizationId) {
            return false;
        }

        // Check if user's organization has access through DocumentRole
        return DocumentRole::where('DocumentManagementID', $documentManagementId)
            ->where('OrganizationID', $userOrganizationId)
            ->exists();
    }

    /**
     * Get all revisions for a document
     * User can see revisions if their organization has access via DocumentRole
     */
    public function getDocumentRevisions(Request $request, $documentManagementId): JsonResponse
    {
        try {
            $document = DocumentManagement::findOrFail($documentManagementId);
            $user = $request->auth_user;

            // Check document access
            if (!$this->userHasDocumentAccess($documentManagementId, $user->UserID)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this document'
                ], 403);
            }

            $revisions = DocumentRevision::where('DocumentManagementID', $documentManagementId)
                ->with(['user', 'notesBy'])
                ->orderBy('DocumentRevisionID', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $revisions,
                'document' => $document
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve document revisions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * User request revision
     * User can request if their organization has access via DocumentRole
     */
    public function requestRevision(Request $request, $documentManagementId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Comment' => 'required|string|max:1000'
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
            $user = $request->auth_user;
            $timestamp = Carbon::now()->timestamp;

            $document = DocumentManagement::findOrFail($documentManagementId);

            // Check document access before allowing revision request
            if (!$this->userHasDocumentAccess($documentManagementId, $authUserId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to request revision for this document'
                ], 403);
            }

            // Create revision request dengan VersionNo dari dokumen saat ini
            $revision = DocumentRevision::create([
                'DocumentRevisionID' => $timestamp . random_numbersu(5),
                'DocumentManagementID' => $documentManagementId,
                'ByUserID' => $authUserId,
                'Comment' => $request->Comment,
                'Status' => 'request',
                'VersionNo' => $document->LatestVersionNo // Tambahkan VersionNo saat ini
            ]);

            // kirim email ke semua admin

            // kirim email ke pembuat info comment revision sedang di tinjau oleh admin

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'DocumentRevision',
                'ReferenceRecordID' => $revision->DocumentRevisionID,
                'Data' => json_encode([
                    'DocumentRevisionID' => $revision->DocumentRevisionID,
                    'DocumentManagementID' => $revision->DocumentManagementID,
                    'ByUserID' => $revision->ByUserID,
                    'Comment' => $revision->Comment,
                    'Status' => 'request',
                    'VersionNo' => $revision->VersionNo
                ]),
                'Note' => 'Document revision requested at version ' . $revision->VersionNo
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Revision request submitted successfully',
                'data' => $revision->load('user')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create revision request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin approve revision
     */
    public function approveRevision(Request $request, $revisionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Notes' => 'nullable|string|max:1000'
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
            $timestamp = Carbon::now()->timestamp;

            $revision = DocumentRevision::findOrFail($revisionId);

            // Check if user is admin
            $user = $request->auth_user;
            if (!$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admin can approve revisions'
                ], 403);
            }

            // Check if already processed
            if ($revision->Status !== 'request') {
                return response()->json([
                    'success' => false,
                    'message' => "Revision already {$revision->Status}"
                ], 400);
            }

            $oldStatus = $revision->Status;

            // Update revision status to approve
            $revision->update([
                'Status' => 'approve',
                'Notes' => $request->Notes,
                'NotesByUserID' => $authUserId
            ]);

            // Create audit log for revision approval
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentRevision',
                'ReferenceRecordID' => $revision->DocumentRevisionID,
                'Data' => json_encode([
                    'DocumentRevisionID' => $revision->DocumentRevisionID,
                    'DocumentManagementID' => $revision->DocumentManagementID,
                    'OldStatus' => $oldStatus,
                    'NewStatus' => 'approve',
                    'Notes' => $request->Notes,
                    'ApprovedBy' => $authUserId,
                    'VersionNo' => $revision->VersionNo
                ]),
                'Note' => "Document revision approved at version {$revision->VersionNo} - Ready for version update"
            ]);

            DB::commit();

            //kirim email ke pembuat revision bahwa comment revisionnya sudah di accept oleh admin (bersangkutan)

            return response()->json([
                'success' => true,
                'message' => 'Revision approved successfully. Document version can now be updated separately.',
                'data' => $revision->load(['user', 'notesBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve revision',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin decline revision
     */
    public function declineRevision(Request $request, $revisionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'Notes' => 'required|string|max:1000'
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
            $timestamp = Carbon::now()->timestamp;

            $revision = DocumentRevision::findOrFail($revisionId);

            // Check if user is admin
            $user = $request->auth_user;
            if (!$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admin can decline revisions'
                ], 403);
            }

            // Check if already processed
            if ($revision->Status !== 'request') {
                return response()->json([
                    'success' => false,
                    'message' => "Revision already {$revision->Status}"
                ], 400);
            }

            $oldStatus = $revision->Status;

            // Update revision status to decline
            $revision->update([
                'Status' => 'decline',
                'Notes' => $request->Notes,
                'NotesByUserID' => $authUserId
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentRevision',
                'ReferenceRecordID' => $revision->DocumentRevisionID,
                'Data' => json_encode([
                    'DocumentRevisionID' => $revision->DocumentRevisionID,
                    'DocumentManagementID' => $revision->DocumentManagementID,
                    'OldStatus' => $oldStatus,
                    'NewStatus' => 'decline',
                    'Notes' => $request->Notes,
                    'DeclinedBy' => $authUserId,
                    'VersionNo' => $revision->VersionNo
                ]),
                'Note' => "Document revision declined at version {$revision->VersionNo}"
            ]);

            DB::commit();
            //kirim email ke pembuat revision bahwa comment revisionnya di decline oleh admin (bersangkutan) dengan alasan : ${alasan}

            return response()->json([
                'success' => true,
                'message' => 'Revision declined successfully',
                'data' => $revision->load(['user', 'notesBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to decline revision',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's own revision requests
     * Only show revisions for documents that user's organization has access to
     */
    public function getMyRevisions(Request $request): JsonResponse
    {
        try {
            $user = $request->auth_user;
            $userOrganizationId = $this->getUserOrganizationId($user->UserID);
            
            if (!$userOrganizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User organization not found'
                ], 403);
            }

            // Get document IDs that user's organization has access to
            $accessibleDocumentIds = DocumentRole::where('OrganizationID', $userOrganizationId)
                ->pluck('DocumentManagementID')
                ->toArray();

            $revisions = DocumentRevision::where('ByUserID', $user->UserID)
                ->whereIn('DocumentManagementID', $accessibleDocumentIds)
                ->with(['documentManagement', 'notesBy'])
                ->orderBy('DocumentRevisionID', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $revisions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve your revisions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single revision detail
     * User can see revision if their organization has access via DocumentRole
     */
    public function getRevision(Request $request, $revisionId): JsonResponse
    {
        try {
            $user = $request->auth_user;
            
            $revision = DocumentRevision::with(['documentManagement', 'user', 'notesBy'])
                ->findOrFail($revisionId);

            // Check document access
            if (!$this->userHasDocumentAccess($revision->DocumentManagementID, $user->UserID)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this revision'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $revision
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Revision not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Admin: Get all revisions dengan filter
     */
    public function listRevisions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:request,approve,decline',
            'document_management_id' => 'nullable|integer|exists:DocumentManagement,DocumentManagementID',
            'user_id' => 'nullable|integer|exists:User,UserID',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->auth_user;
            
            // For non-admin users, filter by accessible documents
            $query = DocumentRevision::with(['documentManagement', 'user', 'notesBy']);

            if (!$user->IsAdministrator) {
                $userOrganizationId = $this->getUserOrganizationId($user->UserID);
                if ($userOrganizationId) {
                    $accessibleDocumentIds = DocumentRole::where('OrganizationID', $userOrganizationId)
                        ->pluck('DocumentManagementID')
                        ->toArray();
                    
                    $query->whereIn('DocumentManagementID', $accessibleDocumentIds);
                } else {
                    // If user has no organization, return empty
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('Status', $request->status);
            }

            // Filter by document
            if ($request->filled('document_management_id')) {
                $query->where('DocumentManagementID', $request->document_management_id);
            }

            // Filter by user (requester)
            if ($request->filled('user_id')) {
                $query->where('ByUserID', $request->user_id);
            }

            $revisions = $query->orderBy('DocumentRevisionID', 'desc')
                ->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => $revisions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve revisions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get pending revisions
     */
    public function getPendingRevisions(Request $request): JsonResponse
    {
        try {
            $user = $request->auth_user;
            
            $query = DocumentRevision::where('Status', 'request')
                ->with(['documentManagement', 'user']);

            // For non-admin users, filter by accessible documents
            if (!$user->IsAdministrator) {
                $userOrganizationId = $this->getUserOrganizationId($user->UserID);
                if ($userOrganizationId) {
                    $accessibleDocumentIds = DocumentRole::where('OrganizationID', $userOrganizationId)
                        ->pluck('DocumentManagementID')
                        ->toArray();
                    
                    $query->whereIn('DocumentManagementID', $accessibleDocumentIds);
                } else {
                    // If user has no organization, return empty
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
            }

            $revisions = $query->orderBy('DocumentRevisionID', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $revisions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending revisions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get revision statistics
     */
    public function getRevisionStats(Request $request): JsonResponse
    {
        try {
            $user = $request->auth_user;

            $query = DocumentRevision::query();

            if (!$user->IsAdministrator) {
                $userOrganizationId = $this->getUserOrganizationId($user->UserID);
                if ($userOrganizationId) {
                    $accessibleDocumentIds = DocumentRole::where('OrganizationID', $userOrganizationId)
                        ->pluck('DocumentManagementID')
                        ->toArray();
                    
                    $query->whereIn('DocumentManagementID', $accessibleDocumentIds);
                } else {
                    // If user has no organization, return empty stats
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'total' => 0,
                            'pending' => 0,
                            'approved' => 0,
                            'declined' => 0
                        ]
                    ]);
                }
            }

            $stats = $query->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN Status = "request" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN Status = "approve" THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN Status = "decline" THEN 1 ELSE 0 END) as declined
                ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve revision statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get approved revisions ready for version update
     */
    public function getApprovedRevisions(Request $request): JsonResponse
    {
        try {
            $user = $request->auth_user;
            
            $query = DocumentRevision::where('Status', 'approve')
                ->with(['documentManagement', 'user', 'notesBy']);

            // For non-admin users, filter by accessible documents
            if (!$user->IsAdministrator) {
                $userOrganizationId = $this->getUserOrganizationId($user->UserID);
                if ($userOrganizationId) {
                    $accessibleDocumentIds = DocumentRole::where('OrganizationID', $userOrganizationId)
                        ->pluck('DocumentManagementID')
                        ->toArray();
                    
                    $query->whereIn('DocumentManagementID', $accessibleDocumentIds);
                } else {
                    // If user has no organization, return empty
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
            }

            $revisions = $query->orderBy('DocumentRevisionID', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $revisions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve approved revisions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}