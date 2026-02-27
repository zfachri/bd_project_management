<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Project;
use App\Models\ProjectAssignMember;
use App\Models\ProjectExpense;
use App\Models\ProjectExpenseFile;
use App\Models\ProjectMember;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ProjectControllerHelpers
{
    private function getProjectStatusCode($projectId): ?string
    {
        return ProjectStatus::where('ProjectID', $projectId)->value('ProjectStatusCode');
    }

    private function isProjectVoid($projectId): bool
    {
        return $this->getProjectStatusCode($projectId) === '00';
    }

    private function applyDateFilter(
        $query,
        string $startDateColumn,
        string $endDateColumn,
        Request $request,
        string $today,
        ?string $defaultStartDate = null,
        ?string $defaultEndDate = null
    ): void
    {
        if ($request->filled('SearchDate')) {
            $searchDate = $request->SearchDate;

            $query->whereDate($startDateColumn, '<=', $searchDate)
                ->whereDate($endDateColumn, '>=', $searchDate);
            return;
        }

        if ($request->filled('StartDate') || $request->filled('EndDate')) {
            $startDate = $request->StartDate;
            $endDate = $request->EndDate;

            if ($startDate && $endDate) {
                $query->whereDate($startDateColumn, '<=', $endDate)
                    ->whereDate($endDateColumn, '>=', $startDate);
                return;
            }

            if ($startDate) {
                $query->whereDate($endDateColumn, '>=', $startDate);
            }

            if ($endDate) {
                $query->whereDate($startDateColumn, '<=', $endDate);
            }
            return;
        }

        if ($defaultStartDate && $defaultEndDate) {
            $query->whereDate($startDateColumn, '<=', $defaultEndDate)
                ->whereDate($endDateColumn, '>=', $defaultStartDate);
            return;
        }

        $query->whereDate($startDateColumn, '<=', $today)
            ->whereDate($endDateColumn, '>=', $today);
    }
    /**
     * Handle Task File Upload - Generate Presigned URLs
     */
    private function handleTaskFileUpload($projectId, $taskId, $fileData, $userId, $timestamp)
    {
        $hasConvertedPdf = $fileData['has_converted_pdf'];
        $originalFilename = $fileData['original_filename'];
        $originalExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        // Generate random string untuk converted filename
        $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
        $nameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
        $convertedFilename = $nameWithoutExt . '-' . $randomString . '.' . $originalExtension;

        $fileTimestamp = Carbon::now()->timestamp;
        $fileId = $fileTimestamp . random_numbersu(5);

        // ========================================
        // CASE 1: PDF Upload Only (No conversion)
        // ========================================
        if (!$hasConvertedPdf) {
            // Generate presigned URL for PDF (original = converted)
            $pdfResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/TASK/$taskId",
                filename: $convertedFilename, // PDF dengan random string
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $pdfResult['file_info']['path'];

            // Create file record
            ProjectTaskFile::create([
                'ProjectTaskFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectTaskID' => $taskId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'DocumentPath' => $pdfResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $pdfStaticUrl, // PDF URL
                'DocumentOriginalPath' => $pdfResult['file_info']['path'], // Same as DocumentPath
                'DocumentOriginalUrl' => $pdfStaticUrl, // Same as DocumentUrl
                'IsDelete' => false,
            ]);

            return [
                'ProjectTaskFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'upload_url' => $pdfResult['upload_url'],
                'file_path' => $pdfResult['file_info']['path'],
                'expires_in' => $pdfResult['expires_in'],
            ];
        }

        // ========================================
        // CASE 2: Non-PDF Upload (Needs conversion)
        // ========================================
        else {
            // Generate presigned URL for ORIGINAL file (DOCX, XLSX, etc)
            $originalResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/TASK/$taskId",
                filename: $originalFilename, // Original filename (e.g., report.xlsx)
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $originalResult['file_info']['path'];

            // Generate presigned URL for CONVERTED PDF
            $convertedResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/TASK/$taskId",
                filename: $fileData['converted_filename'], // PDF filename dengan random string
                contentType: 'application/pdf',
                fileSize: $fileData['converted_file_size'] ?? 0
            );

            $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $convertedResult['file_info']['path'];

            // Create file record
            ProjectTaskFile::create([
                'ProjectTaskFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectTaskID' => $taskId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf', // Add random string
                'DocumentPath' => $convertedResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $convertedStaticUrl, // PDF URL
                'DocumentOriginalPath' => $originalResult['file_info']['path'], // Original file path
                'DocumentOriginalUrl' => $originalStaticUrl, // Original file URL
                'IsDelete' => false,
            ]);

            return [
                'ProjectTaskFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf',
                'pdf_upload_url' => $convertedResult['upload_url'],
                'pdf_file_path' => $convertedResult['file_info']['path'],
                'pdf_expires_in' => $convertedResult['expires_in'],
                'original_upload_url' => $originalResult['upload_url'],
                'original_file_path' => $originalResult['file_info']['path'],
                'original_expires_in' => $originalResult['expires_in'],
            ];
        }
    }

    private function newhandleTaskFileUpload($projectId, $taskId, array $fileData, $userId, $timestamp)
    {
        $hasConvertedPdf = $fileData['has_converted_pdf'];
        $originalFilename = $fileData['original_filename'];
        $originalExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        // Generate random string untuk converted filename
        $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
        $nameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
        $convertedFilename = $nameWithoutExt . '-' . $randomString . '.' . $originalExtension;

        $fileTimestamp = Carbon::now()->timestamp;
        $fileId = $fileTimestamp . random_numbersu(5);

        // ========================================
        // CASE 1: PDF Upload Only (No conversion)
        // ========================================
        $uploadedByRole = $fileData['uploaded_by_role'] ?? 'OWNER';
        $filePurpose    = $fileData['file_purpose'] ?? 'ATTACHMENT';

        if (!$hasConvertedPdf) {
            // Generate presigned URL for PDF (original = converted)
            $pdfResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . '/TASK/'.$taskId,
                filename: $convertedFilename, // PDF dengan random string
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $pdfResult['file_info']['path'];

            // Create file record
            ProjectTaskFile::create([
                'ProjectTaskFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectTaskID' => $taskId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'DocumentPath' => $pdfResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $pdfStaticUrl, // PDF URL
                'DocumentOriginalPath' => $pdfResult['file_info']['path'], // Same as DocumentPath
                'DocumentOriginalUrl' => $pdfStaticUrl, // Same as DocumentUrl
                'IsDelete' => false,
            ]);

            return [
                'ProjectTaskFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'upload_url' => $pdfResult['upload_url'],
                'file_path' => $pdfResult['file_info']['path'],
                'expires_in' => $pdfResult['expires_in'],
            ];
        }

        // ========================================
        // CASE 2: Non-PDF Upload (Needs conversion)
        // ========================================
        else {
            // Generate presigned URL for ORIGINAL file (DOCX, XLSX, etc)
            $originalResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/TASK/" . $taskId,
                filename: $originalFilename, // Original filename (e.g., report.xlsx)
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $originalResult['file_info']['path'];

            // Generate presigned URL for CONVERTED PDF
            $convertedResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . '/TASK',
                filename: $fileData['converted_filename'], // PDF filename dengan random string
                contentType: 'application/pdf',
                fileSize: $fileData['converted_file_size'] ?? 0
            );

            $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $convertedResult['file_info']['path'];

            // Create file record
            ProjectTaskFile::create([
                'ProjectTaskFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectTaskID' => $taskId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf', // Add random string
                'DocumentPath' => $convertedResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $convertedStaticUrl, // PDF URL
                'DocumentOriginalPath' => $originalResult['file_info']['path'], // Original file path
                'DocumentOriginalUrl' => $originalStaticUrl, // Original file URL
                'IsDelete' => false,
            ]);

            return [
                'ProjectTaskFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf',
                'pdf_upload_url' => $convertedResult['upload_url'],
                'pdf_file_path' => $convertedResult['file_info']['path'],
                'pdf_expires_in' => $convertedResult['expires_in'],
                'original_upload_url' => $originalResult['upload_url'],
                'original_file_path' => $originalResult['file_info']['path'],
                'original_expires_in' => $originalResult['expires_in'],
            ];
        }
    }

    /**
     * Handle Expense File Upload - Generate Presigned URLs
     */
    private function handleExpenseFileUpload($projectId, $expenseId, $fileData, $userId, $timestamp)
    {
        $hasConvertedPdf = $fileData['has_converted_pdf'];
        $originalFilename = $fileData['original_filename'];
        $originalExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        // Generate random string untuk converted filename
        $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
        $nameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
        $convertedFilename = $nameWithoutExt . '-' . $randomString . '.' . $originalExtension;

        $fileTimestamp = Carbon::now()->timestamp;
        $fileId = $fileTimestamp . random_numbersu(5);

        // ========================================
        // CASE 1: PDF Upload Only (No conversion)
        // ========================================
        if (!$hasConvertedPdf) {
            // Generate presigned URL for PDF (original = converted)
            $pdfResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/EXPENSE/$expenseId",
                filename: $convertedFilename, // PDF dengan random string
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $pdfResult['file_info']['path'];

            // Create file record
            ProjectExpenseFile::create([
                'ProjectExpenseFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectExpenseID' => $expenseId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'DocumentPath' => $pdfResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $pdfStaticUrl, // PDF URL
                'DocumentOriginalPath' => $pdfResult['file_info']['path'], // Same as DocumentPath
                'DocumentOriginalUrl' => $pdfStaticUrl, // Same as DocumentUrl
                'IsDelete' => false,
            ]);

            return [
                'ProjectExpenseFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'upload_url' => $pdfResult['upload_url'],
                'file_path' => $pdfResult['file_info']['path'],
                'expires_in' => $pdfResult['expires_in'],
            ];
        }

        // ========================================
        // CASE 2: Non-PDF Upload (Needs conversion)
        // ========================================
        else {
            // Generate presigned URL for ORIGINAL file (DOCX, XLSX, etc)
            $originalResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/EXPENSE/$expenseId",
                filename: $originalFilename, // Original filename
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $originalResult['file_info']['path'];

            // Generate presigned URL for CONVERTED PDF
            $convertedResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . "/EXPENSE/$expenseId",
                filename: $fileData['converted_filename'], // PDF filename
                contentType: 'application/pdf',
                fileSize: $fileData['converted_file_size'] ?? 0
            );

            $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $convertedResult['file_info']['path'];

            // Create file record
            ProjectExpenseFile::create([
                'ProjectExpenseFileID' => $fileId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ProjectExpenseID' => $expenseId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf', // Add random string
                'DocumentPath' => $convertedResult['file_info']['path'], // PDF path (for display)
                'DocumentUrl' => $convertedStaticUrl, // PDF URL
                'DocumentOriginalPath' => $originalResult['file_info']['path'], // Original file path
                'DocumentOriginalUrl' => $originalStaticUrl, // Original file URL
                'IsDelete' => false,
            ]);

            return [
                'ProjectExpenseFileID' => $fileId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf',
                'pdf_upload_url' => $convertedResult['upload_url'],
                'pdf_file_path' => $convertedResult['file_info']['path'],
                'pdf_expires_in' => $convertedResult['expires_in'],
                'original_upload_url' => $originalResult['upload_url'],
                'original_file_path' => $originalResult['file_info']['path'],
                'original_expires_in' => $originalResult['expires_in'],
            ];
        }
    }

    /**
     * Update Project Status Counts
     */
    private function updateProjectStatus($projectId)
    {
        $status = ProjectStatus::where('ProjectID', $projectId)->first();
        if (!$status) {
            return;
        }

        // Count members
        $totalMembers = ProjectMember::where('ProjectID', $projectId)
            ->where('IsActive', true)
            ->count();

        // Count tasks by priority
        $tasksByPriority = ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->selectRaw('PriorityCode, COUNT(*) as total')
            ->groupBy('PriorityCode')
            ->pluck('total', 'PriorityCode');

        // Count tasks by progress
        $tasksByProgress = ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->selectRaw('ProgressCode, COUNT(*) as total')
            ->groupBy('ProgressCode')
            ->pluck('total', 'ProgressCode');

        $totalTasks = ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->count();

        $totalProgressBar = (float) ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->sum('ProgressBar');

        $averageProgress = $totalTasks > 0
            ? round($totalProgressBar / $totalTasks, 2)
            : 0;

        $totalTasksChecked = ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->where('IsCheck', true)
            ->count();

        // Count expenses
        $totalExpenses = ProjectExpense::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->count();

        $totalExpensesChecked = ProjectExpense::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->where('IsCheck', true)
            ->count();

        $accumulatedExpense = ProjectExpense::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->sum('ExpenseAmount');

        // Get last task update
        $lastTaskUpdate = ProjectTask::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->orderBy('AtTimeStamp', 'desc')
            ->first();

        // Get last expense update
        $lastExpenseUpdate = ProjectExpense::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->orderBy('AtTimeStamp', 'desc')
            ->first();

        // Auto transition:
        // if avg progress > 0 then ON-PROGRESS(11), except VOID/HOLD/COMPLETED.
        $projectStatusCode = $status->ProjectStatusCode;
        if (!in_array($projectStatusCode, ['00', '12', '99'], true)) {
            $projectStatusCode = $averageProgress > 0 ? '11' : '10';
        }

        // Update status
        $status->update([
            'ProjectStatusCode' => $projectStatusCode,
            'TotalMember' => $totalMembers,
            'TotalTaskPriority1' => $tasksByPriority[1] ?? 0,
            'TotalTaskPriority2' => $tasksByPriority[2] ?? 0,
            'TotalTaskPriority3' => $tasksByPriority[3] ?? 0,
            'TotalTask' => $totalTasks,
            'TotalTaskProgress1' => $tasksByProgress[0] ?? 0,
            'TotalTaskProgress2' => $tasksByProgress[1] ?? 0,
            'TotalTaskProgress3' => $tasksByProgress[2] ?? 0,
            'TotalTaskChecked' => $totalTasksChecked,
            'TotalExpense' => $totalExpenses,
            'TotalExpenseChecked' => $totalExpensesChecked,
            'AccumulatedExpense' => $accumulatedExpense,
            'LastTaskUpdateAtTimeStamp' => $lastTaskUpdate?->AtTimeStamp,
            'LastTaskUpdateByUserID' => $lastTaskUpdate?->ByUserID,
            'LastExpenseUpdateAtTimeStamp' => $lastExpenseUpdate?->AtTimeStamp,
            'LastExpenseUpdateByUserID' => $lastExpenseUpdate?->ByUserID,
        ]);
    }

    /**
     * Calculate ProgressCode based on ProgressBar and dates
     * 
     * @param float $progressBar
     * @param string $originalEndDate - Original end date from DB
     * @param string $newEndDate - New end date from request
     * @return int
     */
    private function calculateProgressCode($progressBar, $originalEndDate, $newEndDate)
    {
        // COMPLETED: progress = 100%
        if ($progressBar >= 100) {
            return 2;
        }

        // INITIAL: progress = 0%
        if ($progressBar == 0) {
            return 0;
        }

        // Check if end date has been extended (DELAYED)
        if ($newEndDate > $originalEndDate) {
            return 3; // DELAYED
        }

        // ON-PROGRESS: 0% < progress < 100%, no date extension
        return 1;
    }

    /**
     * Validate task dates are within project dates
     */
    private function validateTaskDates($projectId, $taskStartDate, $taskEndDate)
    {
        $project = Project::where('ProjectID', $projectId)->first();

        if (!$project) {
            return ['valid' => false, 'message' => 'Project not found'];
        }
            // Normalize to DATE ONLY
        $projectStart = Carbon::parse($project->StartDate)->toDateString();
        $projectEnd   = Carbon::parse($project->EndDate)->toDateString();

        $taskStart = Carbon::parse($taskStartDate)->toDateString();
        $taskEnd   = Carbon::parse($taskEndDate)->toDateString();

        if ($taskStart < $projectStart || $taskEnd > $projectEnd) {
            return [
                'valid' => false,
                'message' => "Task start date must be between project dates ({$project->StartDate} - {$project->EndDate})"
            ];
        }

        if ($taskEnd < $projectStart || $taskEnd > $projectEnd) {
            return [
                'valid' => false,
                'message' => "Task end date must be between project dates ({$project->StartDate} - {$project->EndDate})"
            ];
        }

        if ($taskEnd < $taskStart) {
            return ['valid' => false, 'message' => 'Task end date must be after or equal to start date'];
        }

        return ['valid' => true];
    }

    /**
     * Check if user is the ONLY owner of the project
     */
    private function checkSingleOwner($projectId, $userId)
    {
        $owner = ProjectMember::where('ProjectID', $projectId)
            ->where('IsOwner', true)
            ->where('IsActive', true)
            ->first();

        if (!$owner) {
            return ['is_owner' => false, 'message' => 'No active owner found for this project'];
        }

        if ($owner->UserID != $userId) {
            return ['is_owner' => false, 'message' => 'Only the project owner can perform this action'];
        }

        return ['is_owner' => true, 'owner' => $owner];
    }

    private function canUpdateTask($projectId, $taskId, $userId, $user=null)
    {
        // OWNER
        $isOwner = ProjectMember::where('ProjectID', $projectId)
            ->where('UserID', $userId)
            ->where('IsOwner', true)
            ->where('IsActive', true)
            ->exists();

        if ($isOwner || $user->IsAdministrator) {
            return [
                'allowed' => true,
                'role' => 'OWNER',
            ];
        }

        // ASSIGNEE
        $isAssignee = ProjectAssignMember::where('ProjectTaskID', $taskId)
            ->whereExists(function ($q) use ($userId) {
                $q->selectRaw(1)
                    ->from('ProjectMember')
                    ->whereColumn(
                        'ProjectMember.ProjectMemberID',
                        'ProjectAssignMember.ProjectMemberID'
                    )
                    ->where('ProjectMember.UserID', $userId)
                    ->where('ProjectMember.IsActive', true);
            })
            ->exists();

        if ($isAssignee) {
            return [
                'allowed' => true,
                'role' => 'ASSIGNEE'
            ];
        }

        return [
            'allowed' => false,
            'role' => null,
        ];
    }

    private function filterTaskUpdateData(array $input, string $role): array
    {
        $ownerFields = [
            'ParentProjectTaskID',
            'SequenceNo',
            'PriorityCode',
            'TaskDescription',
            'StartDate',
            'EndDate',
            'ProgressBar',
            'Note',
            'IsCheck',
        ];

        $assigneeFields = [
            'ProgressBar',
            'Note',
        ];

        if ($role === 'OWNER') {
            return array_intersect_key($input, array_flip($ownerFields));
        }

        if ($role === 'ASSIGNEE') {
            return array_intersect_key($input, array_flip($assigneeFields));
        }

        return [];
    }

    /**
     * Handle Project Document Upload - Generate Presigned URLs
     */
    private function handleProjectFileUpload($projectId, $fileData, $userId, $timestamp)
    {
        $hasConvertedPdf = $fileData['has_converted_pdf'];
        $originalFilename = $fileData['original_filename'];
        $originalExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        // Generate random string untuk converted filename
        $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
        $nameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
        $convertedFilename = $nameWithoutExt . '-' . $randomString . '.' . $originalExtension;

        // ========================================
        // CASE 1: PDF Upload Only (No conversion)
        // ========================================
        if (!$hasConvertedPdf) {
            // Generate presigned URL for PDF (original = converted)
            $pdfResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . '/DOCUMENT',
                filename: $convertedFilename, // PDF dengan random string
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $pdfResult['file_info']['path'];

            // Update project dengan document path
            Project::where('ProjectID', $projectId)->update([
                'DocumentOriginalPath' => $pdfResult['file_info']['path'],
                'DocumentOriginalUrl' => $pdfStaticUrl,
                'DocumentPath' => $pdfResult['file_info']['path'],
                'DocumentUrl' => $pdfStaticUrl,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'U',
            ]);

            return [
                'ProjectDocumentID' => $projectId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => $convertedFilename,
                'original_upload_url' => $pdfResult['upload_url'],
                'original_file_path' => $pdfResult['file_info']['path'],
                'originalexpires_in' => $pdfResult['expires_in'],
            ];
        }

        // ========================================
        // CASE 2: Non-PDF Upload (Needs conversion)
        // ========================================
        else {
            // Generate presigned URL for ORIGINAL file (DOCX, XLSX, etc)
            $originalResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . '/DOCUMENT',
                filename: $originalFilename, // Original filename (e.g., report.xlsx)
                contentType: $fileData['original_content_type'],
                fileSize: $fileData['original_file_size'] ?? 0
            );

            $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $originalResult['file_info']['path'];

            // Generate presigned URL for CONVERTED PDF
            $convertedResult = $this->minioService->generatePresignedUploadUrl(
                moduleName: 'Project',
                moduleNameId: (string) $projectId . '/DOCUMENT',
                filename: $fileData['converted_filename'], // PDF filename dengan random string
                contentType: 'application/pdf',
                fileSize: $fileData['converted_file_size'] ?? 0
            );

            $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                . '/' . config('filesystems.disks.minio.bucket')
                . '/' . $convertedResult['file_info']['path'];

            // Update project dengan document paths
            Project::where('ProjectID', $projectId)->update([
                'DocumentOriginalPath' => $originalResult['file_info']['path'],
                'DocumentOriginalUrl' => $originalStaticUrl,
                'DocumentPath' => $convertedResult['file_info']['path'],
                'DocumentUrl' => $convertedStaticUrl,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $userId,
                'OperationCode' => 'U',
            ]);

            return [
                'ProjectDocumentID' => $projectId,
                'OriginalFileName' => $originalFilename,
                'ConvertedFileName' => pathinfo($fileData['converted_filename'], PATHINFO_FILENAME)
                    . '-' . $randomString . '.pdf',
                'pdf_upload_url' => $convertedResult['upload_url'],
                'pdf_file_path' => $convertedResult['file_info']['path'],
                'pdf_expires_in' => $convertedResult['expires_in'],
                'original_upload_url' => $originalResult['upload_url'],
                'original_file_path' => $originalResult['file_info']['path'],
                'original_expires_in' => $originalResult['expires_in'],
            ];
        }
    }

    protected function handleProjectFileEdit(string $projectId,?array $fileData,bool $deleteFile,int $authUserId,int $timestamp): array 
    {
        $update = [];
        $uploadResult = [];

        // =====================
        // DELETE FILE
        // =====================
        if ($deleteFile) {
            $update = [
                'DocumentOriginalPath' => null,
                'DocumentOriginalUrl' => null,
                'DocumentPath' => null,
                'DocumentUrl' => null,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];
        }
        // =====================
        // UPDATE FILE BARU
        // =====================
        elseif ($fileData) {
            $hasConvertedPdf = $fileData['has_converted_pdf'];
            $originalFilename = $fileData['original_filename'];

            // CASE 1: PDF Upload Only (No conversion)
            if (!$hasConvertedPdf) {
                $pdfResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'Project',
                    moduleNameId: (string) $projectId . '/DOCUMENT',
                    filename: $originalFilename,
                    contentType: $fileData['original_content_type'],
                    fileSize: $fileData['original_file_size'] ?? 0
                );

                $pdfStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $pdfResult['file_info']['path'];

                $update = [
                    'DocumentOriginalPath' => $pdfResult['file_info']['path'],
                    'DocumentOriginalUrl' => $pdfStaticUrl,
                    'DocumentPath' => $pdfResult['file_info']['path'],
                    'DocumentUrl' => $pdfStaticUrl,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                ];

                $uploadResult = [
                    'upload_url' => $pdfResult['upload_url'],
                    'file_path' => $pdfResult['file_info']['path'],
                    'expires_in' => $pdfResult['expires_in'],
                ];
            }
            // CASE 2: Non-PDF Upload (Needs conversion)
            else {
                // Original file
                $originalResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'Project',
                    moduleNameId: (string) $projectId . '/DOCUMENT',
                    filename: $originalFilename,
                    contentType: $fileData['original_content_type'],
                    fileSize: $fileData['original_file_size'] ?? 0
                );

                $originalStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $originalResult['file_info']['path'];

                // Converted PDF
                $convertedResult = $this->minioService->generatePresignedUploadUrl(
                    moduleName: 'Project',
                    moduleNameId: (string) $projectId . '/DOCUMENT',
                    filename: $fileData['converted_filename'],
                    contentType: 'application/pdf',
                    fileSize: $fileData['converted_file_size'] ?? 0
                );

                $convertedStaticUrl = rtrim(config('filesystems.disks.minio.endpoint'), '/')
                    . '/' . config('filesystems.disks.minio.bucket')
                    . '/' . $convertedResult['file_info']['path'];

                $update = [
                    'DocumentOriginalPath' => $originalResult['file_info']['path'],
                    'DocumentOriginalUrl' => $originalStaticUrl,
                    'DocumentPath' => $convertedResult['file_info']['path'],
                    'DocumentUrl' => $convertedStaticUrl,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                ];

                $uploadResult = [
                    'pdf_upload_url' => $convertedResult['upload_url'],
                    'pdf_file_path' => $convertedResult['file_info']['path'],
                    'pdf_expires_in' => $convertedResult['expires_in'],
                    'original_upload_url' => $originalResult['upload_url'],
                    'original_file_path' => $originalResult['file_info']['path'],
                    'original_expires_in' => $originalResult['expires_in'],
                ];
            }
        }

        // =====================
        // APPLY UPDATE
        // =====================
        if (!empty($update)) {
            Project::where('ProjectID', $projectId)->update($update);
        }

        return $uploadResult;
    }
}
