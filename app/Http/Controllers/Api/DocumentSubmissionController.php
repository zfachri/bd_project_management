<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentSubmission;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DocumentSubmissionController extends Controller
{
    /**
     * User request document submission
     */
    public function requestSubmission(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:Organization,OrganizationID',
            'comment' => 'required|string',
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

            // Create submission request
            $submission = DocumentSubmission::create([
                'DocumentSubmission' => $timestamp . random_numbersu(5),
                'ByUserID' => $authUserId,
                'OrganizationID' => $request->input('organization_id'),
                'Comment' => $request->input('comment'),
                'Status' => 'request',
                'Notes' => null,
                'NotesByUserID' => null,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'DocumentSubmission',
                'ReferenceRecordID' => $submission->DocumentSubmission,
                'Data' => json_encode([
                    'DocumentSubmission' => $submission->DocumentSubmission,
                    'OrganizationID' => $submission->OrganizationID,
                    'Comment' => $submission->Comment,
                    'Status' => 'request',
                ]),
                'Note' => 'Document submission requested'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document submission request created successfully',
                'data' => [
                    'submission_id' => $submission->DocumentSubmission,
                    'organization_id' => $submission->OrganizationID,
                    'comment' => $submission->Comment,
                    'status' => $submission->Status,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create submission request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of submissions (for admin)
     */
    public function listSubmissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:request,approve,decline',
            'organization_id' => 'nullable|integer|exists:Organization,OrganizationID',
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
            $query = DocumentSubmission::with(['user', 'organization', 'notesBy']);

            // Filter by status
            if ($request->filled('status')) {
                $query->byStatus($request->input('status'));
            }

            // Filter by organization
            if ($request->filled('organization_id')) {
                $query->where('OrganizationID', $request->input('organization_id'));
            }

            // Filter by user (requester)
            if ($request->filled('user_id')) {
                $query->where('ByUserID', $request->input('user_id'));
            }

            // Get submissions
            $submissions = $query->orderBy('DocumentSubmission', 'desc')->get();

            return response()->json([
                'success' => true,
                'message' => 'Submissions retrieved successfully',
                'data' => $submissions,
                'total' => $submissions->count(),
                'filters' => [
                    'status' => $request->input('status'),
                    'organization_id' => $request->input('organization_id'),
                    'user_id' => $request->input('user_id'),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve submissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending submissions (for admin)
     */
    public function getPendingSubmissions(Request $request)
    {
        try {
            $submissions = DocumentSubmission::pending()
                ->with(['user', 'organization'])
                ->orderBy('DocumentSubmission', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Pending submissions retrieved successfully',
                'data' => $submissions,
                'total' => $submissions->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending submissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single submission detail
     */
    public function getSubmission(Request $request, $submissionId)
    {
        try {
            $submission = DocumentSubmission::with(['user', 'organization', 'notesBy'])
                ->findOrFail($submissionId);

            return response()->json([
                'success' => true,
                'message' => 'Submission retrieved successfully',
                'data' => $submission
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Submission not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Admin approve submission
     */
    public function approveSubmission(Request $request, $submissionId)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'required|string',
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

            $submission = DocumentSubmission::findOrFail($submissionId);

            // Check if already processed
            if ($submission->Status !== 'request') {
                return response()->json([
                    'success' => false,
                    'message' => "Submission already {$submission->Status}"
                ], 400);
            }

            // Update submission status to approve
            $submission->update([
                'Status' => 'approve',
                'Notes' => $request->input('notes'),
                'NotesByUserID' => $authUserId,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentSubmission',
                'ReferenceRecordID' => $submission->DocumentSubmission,
                'Data' => json_encode([
                    'DocumentSubmission' => $submission->DocumentSubmission,
                    'OldStatus' => 'request',
                    'NewStatus' => 'approve',
                    'Notes' => $request->input('notes'),
                    'ApprovedBy' => $authUserId,
                ]),
                'Note' => 'Document submission approved'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Submission approved successfully',
                'data' => [
                    'submission_id' => $submission->DocumentSubmission,
                    'status' => $submission->Status,
                    'notes' => $submission->Notes,
                    'approved_by' => $authUserId,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve submission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin decline submission
     */
    public function declineSubmission(Request $request, $submissionId)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'required|string',
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

            $submission = DocumentSubmission::findOrFail($submissionId);

            // Check if already processed
            if ($submission->Status !== 'request') {
                return response()->json([
                    'success' => false,
                    'message' => "Submission already {$submission->Status}"
                ], 400);
            }

            // Update submission status to decline
            $submission->update([
                'Status' => 'decline',
                'Notes' => $request->input('notes'),
                'NotesByUserID' => $authUserId,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'DocumentSubmission',
                'ReferenceRecordID' => $submission->DocumentSubmission,
                'Data' => json_encode([
                    'DocumentSubmission' => $submission->DocumentSubmission,
                    'OldStatus' => 'request',
                    'NewStatus' => 'decline',
                    'Notes' => $request->input('notes'),
                    'DeclinedBy' => $authUserId,
                ]),
                'Note' => 'Document submission declined'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Submission declined successfully',
                'data' => [
                    'submission_id' => $submission->DocumentSubmission,
                    'status' => $submission->Status,
                    'notes' => $submission->Notes,
                    'declined_by' => $authUserId,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to decline submission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's own submissions
     */
    public function getMySubmissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:request,approve,decline',
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

            $query = DocumentSubmission::where('ByUserID', $authUserId)
                ->with(['organization', 'notesBy']);

            // Filter by status
            if ($request->filled('status')) {
                $query->byStatus($request->input('status'));
            }

            $submissions = $query->orderBy('DocumentSubmission', 'desc')->get();

            return response()->json([
                'success' => true,
                'message' => 'Your submissions retrieved successfully',
                'data' => $submissions,
                'total' => $submissions->count(),
                'filters' => [
                    'status' => $request->input('status'),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve submissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get submission statistics
     */
    public function getSubmissionStats(Request $request)
    {
        try {
            $stats = [
                'total' => DocumentSubmission::count(),
                'pending' => DocumentSubmission::pending()->count(),
                'approved' => DocumentSubmission::approved()->count(),
                'declined' => DocumentSubmission::declined()->count(),
            ];

            // Stats by organization (optional)
            if ($request->filled('organization_id')) {
                $orgId = $request->input('organization_id');
                $stats['by_organization'] = [
                    'organization_id' => $orgId,
                    'total' => DocumentSubmission::where('OrganizationID', $orgId)->count(),
                    'pending' => DocumentSubmission::where('OrganizationID', $orgId)->pending()->count(),
                    'approved' => DocumentSubmission::where('OrganizationID', $orgId)->approved()->count(),
                    'declined' => DocumentSubmission::where('OrganizationID', $orgId)->declined()->count(),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Submission statistics retrieved successfully',
                'data' => $stats
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}