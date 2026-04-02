<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailNotificationJob;
use App\Models\AuditLog;
use App\Models\DocumentSubmission;
use App\Models\SystemReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'DocumentSubmission' => DocumentSubmission::generateDailyDocumentSubmissionId(),
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

            $this->sendSubmissionRequestNotification($submission);

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

            $this->sendSubmissionApprovedNotification($submission);

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

            $this->sendSubmissionDeclinedNotification($submission);

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

             /**
             * Base query
             */
            $baseQuery = DocumentSubmission::where('ByUserID', $authUserId);

            /**
             * 🔢 Status counters (ALL data, not affected by filter)
             */
            $statusCounts = [
                'pending' => (clone $baseQuery)->where('status', 'request')->count(),
                'approve' => (clone $baseQuery)->where('status', 'approve')->count(),
                'decline' => (clone $baseQuery)->where('status', 'decline')->count(),
            ];

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
                'stats'   => $statusCounts,
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

    private function sendSubmissionRequestNotification(DocumentSubmission $submission): void
    {
        $adminUsers = User::query()
            ->where('IsAdministrator', true)
            ->whereNotNull('Email')
            ->get(['UserID', 'FullName', 'Email']);

        if ($adminUsers->isEmpty()) {
            return;
        }

        $siteName = $this->getSystemReferenceValue('Document', 'Submission Request Site', '');
        $documentName = $this->resolveSubmissionDocumentName($submission);
        $subject = 'Document Submission Request';
        $fallbackBody = "There is a document submission request for {$documentName}.";

        foreach ($adminUsers as $admin) {
            $this->sendTemplatedEmail(
                [$admin->Email],
                $subject,
                $fallbackBody,
                'Document',
                'Submission Request',
                [
                    'recipient_name' => (string) ($admin->FullName ?: 'Username'),
                    'document_name' => $documentName,
                    'submission_id' => (string) $submission->DocumentSubmission,
                    'site_name' => (string) $siteName,
                ]
            );
        }
    }

    private function sendSubmissionApprovedNotification(DocumentSubmission $submission): void
    {
        $requester = User::find($submission->ByUserID);
        if (!$requester || empty($requester->Email)) {
            return;
        }

        $siteName = $this->getSystemReferenceValue('Document', 'Submission Approved Site', '');
        $documentName = $this->resolveSubmissionDocumentName($submission);
        $subject = 'Document Submission Approved';
        $fallbackBody = "The submission for {$documentName} has been approved.";

        $this->sendTemplatedEmail(
            [$requester->Email],
            $subject,
            $fallbackBody,
            'Document',
            'Submission Approved',
            [
                'recipient_name' => (string) ($requester->FullName ?: 'Username'),
                'document_name' => $documentName,
                'submission_id' => (string) $submission->DocumentSubmission,
                'site_name' => (string) $siteName,
            ]
        );
    }

    private function sendSubmissionDeclinedNotification(DocumentSubmission $submission): void
    {
        $requester = User::find($submission->ByUserID);
        if (!$requester || empty($requester->Email)) {
            return;
        }

        $siteName = $this->getSystemReferenceValue('Document', 'Submission Declined Site', '');
        $documentName = $this->resolveSubmissionDocumentName($submission);
        $subject = 'Document Submission Declined';
        $fallbackBody = "The submission for {$documentName} has been declined.";

        $this->sendTemplatedEmail(
            [$requester->Email],
            $subject,
            $fallbackBody,
            'Document',
            'Submission Declined',
            [
                'recipient_name' => (string) ($requester->FullName ?: 'Username'),
                'document_name' => $documentName,
                'submission_id' => (string) $submission->DocumentSubmission,
                'site_name' => (string) $siteName,
            ]
        );
    }

    private function resolveSubmissionDocumentName(DocumentSubmission $submission): string
    {
        $comment = trim((string) ($submission->Comment ?? ''));
        if ($comment !== '') {
            return strlen($comment) > 80
                ? (substr($comment, 0, 77) . '...')
                : $comment;
        }

        return 'Document Submission';
    }

    private function getSystemReferenceValue(string $referenceName, string $fieldName, ?string $default = null): ?string
    {
        try {
            $value = SystemReference::where('ReferenceName', $referenceName)
                ->where('FieldName', $fieldName)
                ->value('FieldValue');

            if (!is_string($value) || trim($value) === '') {
                return $default;
            }

            return $value;
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve system reference value for document submission email', [
                'reference_name' => $referenceName,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    private function sendTemplatedEmail(
        array $emails,
        string $subject,
        string $fallbackTextBody,
        string $referenceName,
        string $fieldName,
        array $replacements = []
    ): void {
        $templateHtml = $this->resolveHtmlTemplate($referenceName, $fieldName, $replacements);
        if (!empty($templateHtml)) {
            $this->sendHtmlEmail($emails, $subject, $templateHtml);
            return;
        }

        $this->sendSimpleEmail($emails, $subject, $fallbackTextBody);
    }

    private function resolveHtmlTemplate(string $referenceName, string $fieldName, array $replacements = []): ?string
    {
        try {
            $template = SystemReference::where('ReferenceName', $referenceName)
                ->where('FieldName', $fieldName)
                ->value('FieldValue');

            if (!is_string($template) || trim($template) === '') {
                return null;
            }

            $baseReplacements = [
                'app_name' => (string) config('app.name', 'System'),
                'year' => (string) date('Y'),
            ];

            $pairs = [];
            foreach (array_merge($baseReplacements, $replacements) as $key => $value) {
                $pairs['{{' . $key . '}}'] = (string) $value;
            }

            return strtr($template, $pairs);
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve document submission email template', [
                'reference_name' => $referenceName,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function sendHtmlEmail(array $emails, string $subject, string $htmlBody): void
    {
        $recipients = array_values(array_filter($emails, static fn ($email) => is_string($email) && trim($email) !== ''));
        if (empty($recipients)) {
            return;
        }

        SendEmailNotificationJob::dispatch($recipients, $subject, $htmlBody, true, 'document_submission')
            ->onQueue('emails');
    }

    private function sendSimpleEmail(array $emails, string $subject, string $body): void
    {
        $recipients = array_values(array_filter($emails, static fn ($email) => is_string($email) && trim($email) !== ''));
        if (empty($recipients)) {
            return;
        }

        SendEmailNotificationJob::dispatch($recipients, $subject, $body, false, 'document_submission')
            ->onQueue('emails');
    }
}
