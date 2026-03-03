<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ProjectControllerHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectExpense;
use App\Models\ProjectExpenseFile;
use App\Models\ProjectAssignMember;
use App\Models\User;
use App\Services\MinioService;
use App\Models\AuditLog;
use App\Models\MiniGoal;

class ProjectController extends Controller
{
    use ProjectControllerHelpers;

    protected $minioService;

    public function __construct(MinioService $minioService)
    {
        $this->minioService = $minioService;
    }

    /**
     * Create Project (Wizard)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Project Data
            'project.ParentProjectID' => 'nullable|integer',
            'project.LevelNo' => 'required|integer|in:1,2',
            'project.IsChild' => 'required|boolean',
            'project.ProjectCategoryID' => 'nullable|integer',
            'project.ProjectName' => 'required|string',
            'project.ProjectDescription' => 'required|string',
            'project.CurrencyCode' => 'nullable|string|max:3',
            'project.BudgetAmount' => 'nullable|numeric',
            'project.StartDate' => 'required|date_format:Y-m-d',
            'project.EndDate' => 'required|date_format:Y-m-d|after_or_equal:project.StartDate',
            'project.PriorityCode' => 'required|integer|in:1,2,3',

            // Project Document (optional, SINGLE FILE)
            'project_file.original_filename' => 'required_with:project_file|string|max:255',
            'project_file.original_content_type' => 'required_with:project_file|string|max:100',
            'project_file.original_file_size' => 'nullable|integer|min:1',
            'project_file.has_converted_pdf' => 'required_with:project_file|boolean',
            'project_file.converted_filename' => 'nullable|required_if:project_file.has_converted_pdf,true|string|max:255',
            'project_file.converted_file_size' => 'nullable|integer|min:1',


            // Project Status
            'status.ProjectStatusCode' => 'required|string|max:2|in:00,10,11,12,99',
            'status.ProjectStatusReason' => 'nullable|string|max:200',

            // Members (wajib minimal 1 owner)
            'members' => 'required|array|min:1',
            'members.*.UserID' => 'required|integer|exists:User,UserID',
            'members.*.IsOwner' => 'required|boolean',
            'members.*.Title' => 'nullable|string|max:200',

            // MiniGoals (opsional)
            'mini_goals' => 'nullable|array',
            'mini_goals.*.SequenceNo' => 'nullable|integer',
            'mini_goals.*.MiniGoalDescription' => 'required|string|max:200',
            'mini_goals.*.MiniGoalCategoryCode' => 'required|in:1,2,3',
            'mini_goals.*.MiniGoalFirstPrefixCode' => 'nullable|string|max:10',
            'mini_goals.*.MiniGoalLastPrefixCode' => 'nullable|string|max:10',
            'mini_goals.*.TargetValue' => 'required|integer|min:0',
            'mini_goals.*.ActualValue' => 'nullable|integer|min:0',

            // Tasks (opsional)
            'tasks' => 'nullable|array',
            'tasks.*.ParentProjectTaskID' => 'nullable|integer',
            'tasks.*.SequenceNo' => 'nullable|integer',
            'tasks.*.PriorityCode' => 'required|integer|in:1,2,3',
            'tasks.*.TaskDescription' => 'required|string|max:200',
            'tasks.*.StartDate' => 'required|date_format:Y-m-d',
            'tasks.*.EndDate' => 'required|date_format:Y-m-d|after_or_equal:tasks.*.StartDate',
            'tasks.*.ProgressBar' => 'nullable|numeric|min:0|max:100',
            'tasks.*.Note' => 'nullable|string',

            // Task Files (opsional)
            'tasks.*.files' => 'nullable|array',
            'tasks.*.files.*.original_filename' => 'required|string|max:255',
            'tasks.*.files.*.original_content_type' => 'required|string|max:100',
            'tasks.*.files.*.original_file_size' => 'nullable|integer|min:1',
            'tasks.*.files.*.has_converted_pdf' => 'required|boolean',
            'tasks.*.files.*.converted_filename' => 'nullable|required_if:tasks.*.files.*.has_converted_pdf,true|string|max:255',
            'tasks.*.files.*.converted_file_size' => 'nullable|integer|min:1',

            // Task Assigned Members (opsional, based on UserID)
            'tasks.*.assignedMembers' => 'nullable|array',
            'tasks.*.assignedMembers.*' => 'integer|exists:User,UserID',

            // Expenses (opsional)
            'expenses' => 'nullable|array',
            'expenses.*.ExpenseDate' => 'required|date_format:Y-m-d',
            'expenses.*.ExpenseNote' => 'required|string|max:200',
            'expenses.*.CurrencyCode' => 'required|string|max:3',
            'expenses.*.ExpenseAmount' => 'required|numeric',

            // Expense Files (opsional)
            'expenses.*.files' => 'nullable|array',
            'expenses.*.files.*.original_filename' => 'required|string|max:255',
            'expenses.*.files.*.original_content_type' => 'required|string|max:100',
            'expenses.*.files.*.original_file_size' => 'nullable|integer|min:1',
            'expenses.*.files.*.has_converted_pdf' => 'required|boolean',
            'expenses.*.files.*.converted_filename' => 'nullable|required_if:expenses.*.files.*.has_converted_pdf,true|string|max:255',
            'expenses.*.files.*.converted_file_size' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validasi: hanya boleh ada SATU owner
        $owners = collect($request->input('members'))->where('IsOwner', true);
        if ($owners->count() !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Project must have exactly ONE owner'
            ], 422);
        }

        // Validasi: user yang create harus jadi owner
        $authUserId = $request->auth_user_id;
        $user = $request->auth_user;
        if(!$user->IsAdministrator) {
            $isCreatorOwner = $owners->contains('UserID', $authUserId);
            if (!$isCreatorOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project creator must be set as the owner'
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $timestamp = Carbon::now()->timestamp;

            // 1. Create Project
            $projectId = $timestamp . random_numbersu(5);
            $project = Project::create([
                'ProjectID' => $projectId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ParentProjectID' => $request->input('project.ParentProjectID'),
                'LevelNo' => $request->input('project.LevelNo'),
                'IsChild' => $request->input('project.IsChild'),
                'ProjectCategoryID' => $request->input('project.ProjectCategoryID'),
                'ProjectName' => $request->input('project.ProjectName'),
                'ProjectDescription' => $request->input('project.ProjectDescription'),
                'CurrencyCode' => $request->input('project.CurrencyCode', 'IDR'),
                'BudgetAmount' => $request->input('project.BudgetAmount', 0),
                'IsDelete' => false,
                'StartDate' => $request->input('project.StartDate'),
                'EndDate' => $request->input('project.EndDate'),
                'PriorityCode' => $request->input('project.PriorityCode'),
            ]);

            // 1b. Handle Project Document Upload (if any)
            $projectFileUpload = null;

            if ($request->has('project_file')) {
                $projectFileUpload = $this->handleProjectFileUpload(
                    $projectId,
                    $request->input('project_file'),
                    $authUserId,
                    $timestamp
                );
            }

            // 2. Create Project Status
            $projectStatus = ProjectStatus::create([
                'ProjectID' => $projectId,
                'ProjectStatusCode' => 10,
                'ProjectStatusReason' => $request->input('status.ProjectStatusReason'),
                'TotalMember' => 0,
                'TotalTaskPriority1' => 0,
                'TotalTaskPriority2' => 0,
                'TotalTaskPriority3' => 0,
                'TotalTask' => 0,
                'TotalTaskProgress1' => 0,
                'TotalTaskProgress2' => 0,
                'TotalTaskProgress3' => 0,
                'TotalTaskChecked' => 0,
                'TotalExpense' => 0,
                'TotalExpenseChecked' => 0,
                'AccumulatedExpense' => 0,
                'LastTaskUpdateAtTimeStamp' => null,
                'LastTaskUpdateByUserID' => null,
                'LastExpenseUpdateAtTimeStamp' => null,
                'LastExpenseUpdateByUserID' => null,
            ]);

            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => Carbon::now()->timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'ProjectStatus',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode([
                    'ProjectID' => $projectStatus->ProjectID,
                    'ProjectStatusCode' => $projectStatus->ProjectStatusCode,
                    'ProjectStatusReason' => $projectStatus->ProjectStatusReason,
                ]),
                'Note' => 'Project status created via wizard'
            ]);

            // 3. Create Project Members
            $memberMap = []; // Map UserID => ProjectMemberID
            $createdMembers = [];
            $memberUserIdsForNotification = [];

            foreach ($request->input('members') as $memberData) {
                $memberTimestamp = Carbon::now()->timestamp;
                $memberId = $memberTimestamp . random_numbersu(5);

                $member = ProjectMember::create([
                    'ProjectMemberID' => $memberId,
                    'ProjectID' => $projectId,
                    'AtTimeStamp' => $memberTimestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'UserID' => $memberData['UserID'],
                    'IsActive' => true,
                    'IsOwner' => $memberData['IsOwner'],
                    'Title' => $memberData['Title'] ?? null,
                ]);

                $memberMap[$memberData['UserID']] = $memberId;

                AuditLog::create([
                    'AuditLogID' => $memberTimestamp . random_numbersu(5),
                    'AtTimeStamp' => $memberTimestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'ReferenceTable' => 'ProjectMember',
                    'ReferenceRecordID' => $memberId,
                    'Data' => json_encode([
                        'ProjectMemberID' => $memberId,
                        'ProjectID' => $projectId,
                        'UserID' => $memberData['UserID'],
                        'IsOwner' => $memberData['IsOwner'],
                        'Title' => $memberData['Title'] ?? null,
                    ]),
                    'Note' => 'Project member created via wizard'
                ]);

                $createdMembers[] = [
                    'ProjectMemberID' => $memberId,
                    'UserID' => $memberData['UserID'],
                    'IsOwner' => $memberData['IsOwner'],
                    'Title' => $memberData['Title'] ?? null,
                ];

                $memberUserIdsForNotification[] = (int) $memberData['UserID'];
            }

            // 4. Create MiniGoals (if any)
            $createdMiniGoals = [];
            if ($request->has('mini_goals') && !empty($request->input('mini_goals'))) {
                foreach ($request->input('mini_goals') as $miniGoalData) {
                    $miniGoalTimestamp = Carbon::now()->timestamp;
                    $miniGoalId = $miniGoalTimestamp . random_numbersu(5);

                    $miniGoal = MiniGoal::create([
                        'MiniGoalID' => $miniGoalId,
                        'AtTimeStamp' => $miniGoalTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ProjectID' => $projectId,
                        'SequenceNo' => $miniGoalData['SequenceNo'] ?? null,
                        'MiniGoalDescription' => $miniGoalData['MiniGoalDescription'],
                        'MiniGoalCategoryCode' => $miniGoalData['MiniGoalCategoryCode'],
                        'MiniGoalFirstPrefixCode' => $miniGoalData['MiniGoalFirstPrefixCode'] ?? "",
                        'MiniGoalLastPrefixCode' => $miniGoalData['MiniGoalLastPrefixCode'] ?? "",
                        'TargetValue' => $miniGoalData['TargetValue'],
                        'ActualValue' => $miniGoalData['ActualValue'] ?? 0,
                        'IsDelete' => false,
                    ]);

                    AuditLog::create([
                        'AuditLogID' => $miniGoalTimestamp . random_numbersu(5),
                        'AtTimeStamp' => $miniGoalTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'MiniGoal',
                        'ReferenceRecordID' => $miniGoalId,
                        'Data' => json_encode([
                            'MiniGoalID' => $miniGoalId,
                            'ProjectID' => $projectId,
                            'SequenceNo' => $miniGoalData['SequenceNo'] ?? null,
                            'MiniGoalDescription' => $miniGoalData['MiniGoalDescription'],
                            'MiniGoalCategoryCode' => $miniGoalData['MiniGoalCategoryCode'],
                            'MiniGoalFirstPrefixCode' => $miniGoalData['MiniGoalFirstPrefixCode'] ?? "",
                            'MiniGoalLastPrefixCode' => $miniGoalData['MiniGoalLastPrefixCode'] ?? "",
                            'TargetValue' => $miniGoalData['TargetValue'],
                            'ActualValue' => $miniGoalData['ActualValue'] ?? 0,
                        ]),
                        'Note' => 'Mini goal created via wizard'
                    ]);

                    $createdMiniGoals[] = [
                        'MiniGoalID' => $miniGoalId,
                        'MiniGoalDescription' => $miniGoalData['MiniGoalDescription'],
                        'MiniGoalCategoryCode' => $miniGoalData['MiniGoalCategoryCode'],
                        'MiniGoalFirstPrefixCode' => $miniGoalData['MiniGoalFirstPrefixCode'] ?? "",
                        'MiniGoalLastPrefixCode' => $miniGoalData['MiniGoalLastPrefixCode'] ?? "",
                        'TargetValue' => $miniGoalData['TargetValue'],
                        'ActualValue' => $miniGoalData['ActualValue'] ?? 0,
                    ];
                }
            }

            // 5. Create Tasks (if any)
            $createdTasks = [];
            $taskAssignmentNotifications = [];
            if ($request->has('tasks') && !empty($request->input('tasks'))) {
                foreach ($request->input('tasks') as $taskIndex => $taskData) {
                    $taskTimestamp = Carbon::now()->timestamp;
                    $taskId = $taskTimestamp . random_numbersu(5);

                    // Validate task dates are within project dates
                    $dateValidation = $this->validateTaskDates(
                        $projectId,
                        $taskData['StartDate'],
                        $taskData['EndDate']
                    );

                    if (!$dateValidation['valid']) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Task #" . ($taskIndex + 1) . ": " . $dateValidation['message'],
                        ], 422);
                    }

                    // Calculate ProgressCode based on ProgressBar + IsCheck
                    $progressBar = $taskData['ProgressBar'] ?? 0;
                    $progressCode = $this->calculateProgressCode(
                        $progressBar,
                        false,
                        $taskData['EndDate'],
                        $taskData['EndDate']
                    );

                    $task = ProjectTask::create([
                        'ProjectTaskID' => $taskId,
                        'AtTimeStamp' => $taskTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ProjectID' => $projectId,
                        'ParentProjectTaskID' => $taskData['ParentProjectTaskID'] ?? null,
                        'SequenceNo' => $taskData['SequenceNo'] ?? null,
                        'PriorityCode' => $taskData['PriorityCode'],
                        'TaskDescription' => $taskData['TaskDescription'],
                        'StartDate' => $taskData['StartDate'],
                        'EndDate' => $taskData['EndDate'],
                        'ProgressCode' => $progressCode,
                        'ProgressBar' => $progressBar,
                        'Note' => $taskData['Note'] ?? null,
                        'IsDelete' => false,
                        'IsCheck' => false,
                    ]);

                    AuditLog::create([
                        'AuditLogID' => $taskTimestamp . random_numbersu(5),
                        'AtTimeStamp' => $taskTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'ProjectTask',
                        'ReferenceRecordID' => $taskId,
                        'Data' => json_encode([
                            'ProjectTaskID' => $taskId,
                            'ProjectID' => $projectId,
                            'ParentProjectTaskID' => $taskData['ParentProjectTaskID'] ?? null,
                            'SequenceNo' => $taskData['SequenceNo'] ?? null,
                            'PriorityCode' => $taskData['PriorityCode'],
                            'TaskDescription' => $taskData['TaskDescription'],
                            'StartDate' => $taskData['StartDate'],
                            'EndDate' => $taskData['EndDate'],
                            'ProgressCode' => $progressCode,
                            'ProgressBar' => $progressBar,
                            'Note' => $taskData['Note'] ?? null,
                        ]),
                        'Note' => 'Project task created via wizard'
                    ]);

                    $taskFileUrls = [];

                    // 5a. Generate Presigned URLs for Task Files (if any)
                    if (!empty($taskData['files'])) {
                        foreach ($taskData['files'] as $fileData) {
                            $fileResult = $this->handleTaskFileUpload(
                                $projectId,
                                $taskId,
                                $fileData,
                                $authUserId,
                                $taskTimestamp
                            );

                            $taskFileUrls[] = $fileResult;

                            AuditLog::create([
                                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                                'AtTimeStamp' => Carbon::now()->timestamp,
                                'ByUserID' => $authUserId,
                                'OperationCode' => 'I',
                                'ReferenceTable' => 'ProjectTaskFile',
                                'ReferenceRecordID' => $fileResult['ProjectTaskFileID'],
                                'Data' => json_encode([
                                    'ProjectTaskFileID' => $fileResult['ProjectTaskFileID'],
                                    'ProjectTaskID' => $taskId,
                                    'ProjectID' => $projectId,
                                    'OriginalFileName' => $fileResult['OriginalFileName'] ?? null,
                                    'ConvertedFileName' => $fileResult['ConvertedFileName'] ?? null,
                                    'DocumentPath' => $fileResult['file_path'] ?? ($fileResult['pdf_file_path'] ?? null),
                                    'DocumentOriginalPath' => $fileResult['original_file_path'] ?? null,
                                ]),
                                'Note' => 'Project task file created via wizard'
                            ]);
                        }
                    }

                    // 5b. Assign Members to Task (if any) - based on UserID
                    $assignedMembers = [];
                    if (!empty($taskData['assignedMembers'])) {
                        foreach ($taskData['assignedMembers'] as $assignedUserId) {
                            if (isset($memberMap[$assignedUserId])) {
                                $assignTimestamp = Carbon::now()->timestamp;
                                $assignId = $assignTimestamp . random_numbersu(5);

                                ProjectAssignMember::create([
                                    'ProjectAssignMemberID' => $assignId,
                                    'AtTimeStamp' => $assignTimestamp,
                                    'ByUserID' => $authUserId,
                                    'OperationCode' => 'I',
                                    'ProjectMemberID' => $memberMap[$assignedUserId],
                                    'ProjectTaskID' => $taskId,
                                ]);

                                $assignedMembers[] = [
                                    'UserID' => $assignedUserId,
                                    'ProjectMemberID' => $memberMap[$assignedUserId],
                                ];
                            }
                        }
                    }

                    $createdTasks[] = [
                        'ProjectTaskID' => $taskId,
                        'TaskDescription' => $taskData['TaskDescription'],
                        'PriorityCode' => $taskData['PriorityCode'],
                        'ProgressCode' => $progressCode,
                        'ProgressBar' => $progressBar,
                        'files' => $taskFileUrls,
                        'assignedMembers' => $assignedMembers,
                    ];

                    if (!empty($assignedMembers)) {
                        $taskAssignmentNotifications[] = [
                            'task_id' => $taskId,
                            'task_description' => $taskData['TaskDescription'],
                            'member_ids' => array_values(array_unique(array_map(
                                static fn($member) => (int) $member['UserID'],
                                $assignedMembers
                            ))),
                        ];
                    }
                }
            }

            // 6. Create Expenses (if any)
            $createdExpenses = [];
            if ($request->has('expenses') && !empty($request->input('expenses'))) {
                foreach ($request->input('expenses') as $expenseData) {
                    $expenseTimestamp = Carbon::now()->timestamp;
                    $expenseId = $expenseTimestamp . random_numbersu(5);

                    $expense = ProjectExpense::create([
                        'ProjectExpenseID' => $expenseId,
                        'AtTimeStamp' => $expenseTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ProjectID' => $projectId,
                        'ExpenseDate' => $expenseData['ExpenseDate'],
                        'ExpenseNote' => $expenseData['ExpenseNote'],
                        'CurrencyCode' => $expenseData['CurrencyCode'],
                        'ExpenseAmount' => $expenseData['ExpenseAmount'],
                        'IsDelete' => false,
                        'IsCheck' => false,
                    ]);

                    AuditLog::create([
                        'AuditLogID' => $expenseTimestamp . random_numbersu(5),
                        'AtTimeStamp' => $expenseTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'ProjectExpense',
                        'ReferenceRecordID' => $expenseId,
                        'Data' => json_encode([
                            'ProjectExpenseID' => $expenseId,
                            'ProjectID' => $projectId,
                            'ExpenseDate' => $expenseData['ExpenseDate'],
                            'ExpenseNote' => $expenseData['ExpenseNote'],
                            'CurrencyCode' => $expenseData['CurrencyCode'],
                            'ExpenseAmount' => $expenseData['ExpenseAmount'],
                        ]),
                        'Note' => 'Project expense created via wizard'
                    ]);

                    $expenseFileUrls = [];

                    // 6a. Generate Presigned URLs for Expense Files (if any)
                    if (!empty($expenseData['files'])) {
                        foreach ($expenseData['files'] as $fileData) {
                            $fileResult = $this->handleExpenseFileUpload(
                                $projectId,
                                $expenseId,
                                $fileData,
                                $authUserId,
                                $expenseTimestamp
                            );

                            $expenseFileUrls[] = $fileResult;

                            AuditLog::create([
                                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                                'AtTimeStamp' => Carbon::now()->timestamp,
                                'ByUserID' => $authUserId,
                                'OperationCode' => 'I',
                                'ReferenceTable' => 'ProjectExpenseFile',
                                'ReferenceRecordID' => $fileResult['ProjectExpenseFileID'],
                                'Data' => json_encode([
                                    'ProjectExpenseFileID' => $fileResult['ProjectExpenseFileID'],
                                    'ProjectExpenseID' => $expenseId,
                                    'ProjectID' => $projectId,
                                    'OriginalFileName' => $fileResult['OriginalFileName'] ?? null,
                                    'ConvertedFileName' => $fileResult['ConvertedFileName'] ?? null,
                                    'DocumentPath' => $fileResult['file_path'] ?? ($fileResult['pdf_file_path'] ?? null),
                                    'DocumentOriginalPath' => $fileResult['original_file_path'] ?? null,
                                ]),
                                'Note' => 'Project expense file created via wizard'
                            ]);
                        }
                    }

                    $createdExpenses[] = [
                        'ProjectExpenseID' => $expenseId,
                        'ExpenseNote' => $expenseData['ExpenseNote'],
                        'ExpenseAmount' => $expenseData['ExpenseAmount'],
                        'files' => $expenseFileUrls,
                    ];
                }
            }

            // 7. Update Project Status with counts
            $this->updateProjectStatus($projectId);

            // 8. Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'Project',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'ProjectDescription' => $project->ProjectDescription,
                    'LevelNo' => $project->LevelNo,
                    'StartDate' => $project->StartDate,
                    'EndDate' => $project->EndDate,
                    'PriorityCode' => $project->PriorityCode,
                    'StatusCode' => $request->input('status.ProjectStatusCode'),
                    'TotalMembers' => count($createdMembers),
                    'TotalMiniGoals' => count($createdMiniGoals),
                    'TotalTasks' => count($createdTasks),
                    'TotalExpenses' => count($createdExpenses),
                ]),
                'Note' => 'Project created via wizard'
            ]);

            DB::commit();

            $this->sendProjectCreatedNotifications(
                $project,
                $memberUserIdsForNotification
            );

            foreach ($taskAssignmentNotifications as $assignmentNotification) {
                $this->sendAssigneeNotification(
                    $project,
                    $assignmentNotification['task_id'],
                    $assignmentNotification['task_description'],
                    $assignmentNotification['member_ids'],
                    'assigned'
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Project created successfully',
                'data' => [
                    'ProjectID' => $projectId,
                    'ProjectDescription' => $project->ProjectDescription,
                    'ProjectName' => $project->ProjectName,
                    'ProjectDocument' => $projectFileUpload,
                    'StartDate' => $project->StartDate,
                    'EndDate' => $project->EndDate,
                    'members' => $createdMembers,
                    'mini_goals' => $createdMiniGoals,
                    'tasks' => $createdTasks,
                    'expenses' => $createdExpenses,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function old_store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Project Data
            'project.ParentProjectID' => 'nullable|integer',
            'project.LevelNo' => 'required|integer|in:1,2',
            'project.IsChild' => 'required|boolean',
            'project.ProjectCategoryID' => 'nullable|integer',
            'project.ProjectDescription' => 'required|string',
            'project.CurrencyCode' => 'nullable|string|max:3',
            'project.BudgetAmount' => 'nullable|numeric',
            'project.StartDate' => 'required|date_format:Y-m-d',
            'project.EndDate' => 'required|date_format:Y-m-d|after_or_equal:project.StartDate',
            'project.PriorityCode' => 'required|integer|in:1,2,3',

            // Project Status
            'status.ProjectStatusCode' => 'required|string|max:2|in:00,10,11,12,99',
            'status.ProjectStatusReason' => 'nullable|string|max:200',

            // Members (wajib minimal 1)
            'members' => 'required|array|min:1',
            'members.*.UserID' => 'required|integer|exists:User,UserID',
            'members.*.IsOwner' => 'required|boolean',
            'members.*.Title' => 'nullable|string|max:200',

            // Tasks (opsional)
            'tasks' => 'nullable|array',
            'tasks.*.ParentProjectTaskID' => 'nullable|integer',
            'tasks.*.SequenceNo' => 'nullable|integer',
            'tasks.*.PriorityCode' => 'required|integer|in:1,2,3',
            'tasks.*.TaskDescription' => 'required|string|max:200',
            'tasks.*.StartDate' => 'required|date_format:Y-m-d',
            'tasks.*.EndDate' => 'required|date_format:Y-m-d|after_or_equal:tasks.*.StartDate',
            'tasks.*.ProgressCode' => 'required|integer|in:0,1,2',
            'tasks.*.Note' => 'nullable|string',

            // Task Files (opsional)
            'tasks.*.files' => 'nullable|array',
            'tasks.*.files.*.original_filename' => 'required|string|max:255',
            'tasks.*.files.*.original_content_type' => 'required|string|max:100',
            'tasks.*.files.*.original_file_size' => 'nullable|integer|min:1',
            'tasks.*.files.*.has_converted_pdf' => 'required|boolean',
            'tasks.*.files.*.converted_filename' => 'nullable|required_if:tasks.*.files.*.has_converted_pdf,true|string|max:255',
            'tasks.*.files.*.converted_file_size' => 'nullable|integer|min:1',

            // Task Assigned Members (opsional, based on UserID)
            'tasks.*.assignedMembers' => 'nullable|array',
            'tasks.*.assignedMembers.*' => 'integer|exists:User,UserID',

            // Expenses (opsional)
            'expenses' => 'nullable|array',
            'expenses.*.ExpenseDate' => 'required|date_format:Y-m-d',
            'expenses.*.ExpenseNote' => 'required|string|max:200',
            'expenses.*.CurrencyCode' => 'required|string|max:3',
            'expenses.*.ExpenseAmount' => 'required|numeric',

            // Expense Files (opsional)
            'expenses.*.files' => 'nullable|array',
            'expenses.*.files.*.original_filename' => 'required|string|max:255',
            'expenses.*.files.*.original_content_type' => 'required|string|max:100',
            'expenses.*.files.*.original_file_size' => 'nullable|integer|min:1',
            'expenses.*.files.*.has_converted_pdf' => 'required|boolean',
            'expenses.*.files.*.converted_filename' => 'nullable|required_if:expenses.*.files.*.has_converted_pdf,true|string|max:255',
            'expenses.*.files.*.converted_file_size' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validasi: minimal harus ada 1 owner
        $hasOwner = collect($request->input('members'))->contains('IsOwner', true);
        if (!$hasOwner) {
            return response()->json([
                'success' => false,
                'message' => 'At least one member must be set as owner'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $timestamp = Carbon::now()->timestamp;

            // 1. Create Project
            $projectId = $timestamp . random_numbersu(5);
            $project = Project::create([
                'ProjectID' => $projectId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ParentProjectID' => $request->input('project.ParentProjectID'),
                'LevelNo' => $request->input('project.LevelNo'),
                'IsChild' => $request->input('project.IsChild'),
                'ProjectCategoryID' => $request->input('project.ProjectCategoryID'),
                'ProjectDescription' => $request->input('project.ProjectDescription'),
                'CurrencyCode' => $request->input('project.CurrencyCode', 'IDR'),
                'BudgetAmount' => $request->input('project.BudgetAmount', 0),
                'IsDelete' => false,
                'StartDate' => $request->input('project.StartDate'),
                'EndDate' => $request->input('project.EndDate'),
                'PriorityCode' => $request->input('project.PriorityCode'),
            ]);

            // 2. Create Project Status
            ProjectStatus::create([
                'ProjectID' => $projectId,
                'ProjectStatusCode' => $request->input('status.ProjectStatusCode'),
                'ProjectStatusReason' => $request->input('status.ProjectStatusReason'),
                'TotalMember' => 0,
                'TotalTaskPriority1' => 0,
                'TotalTaskPriority2' => 0,
                'TotalTaskPriority3' => 0,
                'TotalTask' => 0,
                'TotalTaskProgress1' => 0,
                'TotalTaskProgress2' => 0,
                'TotalTaskProgress3' => 0,
                'TotalTaskChecked' => 0,
                'TotalExpense' => 0,
                'TotalExpenseChecked' => 0,
                'AccumulatedExpense' => 0,
                'LastTaskUpdateAtTimeStamp' => null,
                'LastTaskUpdateByUserID' => null,
                'LastExpenseUpdateAtTimeStamp' => null,
                'LastExpenseUpdateByUserID' => null,
            ]);

            // 3. Create Project Members
            $memberMap = []; // Map UserID => ProjectMemberID
            $createdMembers = [];

            foreach ($request->input('members') as $memberData) {
                $memberTimestamp = Carbon::now()->timestamp;
                $memberId = $memberTimestamp . random_numbersu(5);

                $member = ProjectMember::create([
                    'ProjectMemberID' => $memberId,
                    'ProjectID' => $projectId,
                    'AtTimeStamp' => $memberTimestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'UserID' => $memberData['UserID'],
                    'IsActive' => true,
                    'IsOwner' => $memberData['IsOwner'],
                    'Title' => $memberData['Title'] ?? null,
                ]);

                $memberMap[$memberData['UserID']] = $memberId;

                $createdMembers[] = [
                    'ProjectMemberID' => $memberId,
                    'UserID' => $memberData['UserID'],
                    'IsOwner' => $memberData['IsOwner'],
                    'Title' => $memberData['Title'] ?? null,
                ];
            }

            // 4. Create Tasks (if any)
            $createdTasks = [];
            if ($request->has('tasks') && !empty($request->input('tasks'))) {
                foreach ($request->input('tasks') as $taskIndex => $taskData) {
                    $taskTimestamp = Carbon::now()->timestamp;
                    $taskId = $taskTimestamp . random_numbersu(5);

                    $task = ProjectTask::create([
                        'ProjectTaskID' => $taskId,
                        'AtTimeStamp' => $taskTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ProjectID' => $projectId,
                        'ParentProjectTaskID' => $taskData['ParentProjectTaskID'] ?? null,
                        'SequenceNo' => $taskData['SequenceNo'] ?? null,
                        'PriorityCode' => $taskData['PriorityCode'],
                        'TaskDescription' => $taskData['TaskDescription'],
                        'StartDate' => $taskData['StartDate'],
                        'EndDate' => $taskData['EndDate'],
                        'ProgressCode' => $taskData['ProgressCode'],
                        'Note' => $taskData['Note'] ?? null,
                        'IsDelete' => false,
                        'IsCheck' => false,
                    ]);

                    $taskFileUrls = [];

                    // 4a. Generate Presigned URLs for Task Files (if any)
                    if (!empty($taskData['files'])) {
                        foreach ($taskData['files'] as $fileData) {
                            $fileResult = $this->handleTaskFileUpload(
                                $projectId,
                                $taskId,
                                $fileData,
                                $authUserId,
                                $taskTimestamp
                            );

                            $taskFileUrls[] = $fileResult;
                        }
                    }

                    // 4b. Assign Members to Task (if any) - based on UserID
                    $assignedMembers = [];
                    if (!empty($taskData['assignedMembers'])) {
                        foreach ($taskData['assignedMembers'] as $assignedUserId) {
                            if (isset($memberMap[$assignedUserId])) {
                                $assignTimestamp = Carbon::now()->timestamp;
                                $assignId = $assignTimestamp . random_numbersu(5);

                                ProjectAssignMember::create([
                                    'ProjectAssignMemberID' => $assignId,
                                    'AtTimeStamp' => $assignTimestamp,
                                    'ByUserID' => $authUserId,
                                    'OperationCode' => 'I',
                                    'ProjectMemberID' => $memberMap[$assignedUserId],
                                    'ProjectTaskID' => $taskId,
                                ]);

                                $assignedMembers[] = [
                                    'UserID' => $assignedUserId,
                                    'ProjectMemberID' => $memberMap[$assignedUserId],
                                ];
                            }
                        }
                    }

                    $createdTasks[] = [
                        'ProjectTaskID' => $taskId,
                        'TaskDescription' => $taskData['TaskDescription'],
                        'PriorityCode' => $taskData['PriorityCode'],
                        'ProgressCode' => $taskData['ProgressCode'],
                        'files' => $taskFileUrls,
                        'assignedMembers' => $assignedMembers,
                    ];
                }
            }

            // 5. Create Expenses (if any)
            $createdExpenses = [];
            if ($request->has('expenses') && !empty($request->input('expenses'))) {
                foreach ($request->input('expenses') as $expenseData) {
                    $expenseTimestamp = Carbon::now()->timestamp;
                    $expenseId = $expenseTimestamp . random_numbersu(5);

                    $expense = ProjectExpense::create([
                        'ProjectExpenseID' => $expenseId,
                        'AtTimeStamp' => $expenseTimestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ProjectID' => $projectId,
                        'ExpenseDate' => $expenseData['ExpenseDate'],
                        'ExpenseNote' => $expenseData['ExpenseNote'],
                        'CurrencyCode' => $expenseData['CurrencyCode'],
                        'ExpenseAmount' => $expenseData['ExpenseAmount'],
                        'IsDelete' => false,
                        'IsCheck' => false,
                    ]);

                    $expenseFileUrls = [];

                    // 5a. Generate Presigned URLs for Expense Files (if any)
                    if (!empty($expenseData['files'])) {
                        foreach ($expenseData['files'] as $fileData) {
                            $fileResult = $this->handleExpenseFileUpload(
                                $projectId,
                                $expenseId,
                                $fileData,
                                $authUserId,
                                $expenseTimestamp
                            );

                            $expenseFileUrls[] = $fileResult;
                        }
                    }

                    $createdExpenses[] = [
                        'ProjectExpenseID' => $expenseId,
                        'ExpenseNote' => $expenseData['ExpenseNote'],
                        'ExpenseAmount' => $expenseData['ExpenseAmount'],
                        'files' => $expenseFileUrls,
                    ];
                }
            }

            // 6. Update Project Status with counts
            $this->updateProjectStatus($projectId);

            // 7. Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'Project',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'ProjectDescription' => $project->ProjectDescription,
                    'LevelNo' => $project->LevelNo,
                    'StartDate' => $project->StartDate,
                    'EndDate' => $project->EndDate,
                    'PriorityCode' => $project->PriorityCode,
                    'StatusCode' => $request->input('status.ProjectStatusCode'),
                    'TotalMembers' => count($createdMembers),
                    'TotalTasks' => count($createdTasks),
                    'TotalExpenses' => count($createdExpenses),
                ]),
                'Note' => 'Project created via wizard'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Project created successfully',
                'data' => [
                    'ProjectID' => $projectId,
                    'ProjectDescription' => $project->ProjectDescription,
                    'StartDate' => $project->StartDate,
                    'EndDate' => $project->EndDate,
                    'members' => $createdMembers,
                    'tasks' => $createdTasks,
                    'expenses' => $createdExpenses,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List Projects (Role Aware)
     *
     * GET /projects
     */
    public function index(Request $request)
    {
        try {
            // =========================
            // VALIDATOR
            // =========================
            $validator = Validator::make($request->all(), [
                'page'      => 'nullable|integer|min:1',
                'per_page'  => 'nullable|integer|min:1|max:100',
                'Search'    => 'nullable|string|max:100',
                'StartDate' => 'nullable|date_format:Y-m-d',
                'EndDate'   => 'nullable|date_format:Y-m-d|after_or_equal:StartDate',
            ], [
                'EndDate.after_or_equal' => 'EndDate must be greater than or equal to StartDate.'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors(),
                ], 422);
            }
            $authUser   = $request->auth_user;
            $authUserId = $request->auth_user_id;
            // Sesuaikan field admin sesuai User model
            $isAdmin = (bool) ($authUser->IsAdministrator ?? false);
            $perPage    = $request->per_page ?? 10;
            $page       = $request->page ?? 1;
            // ----------------------------------------
            // BASE QUERY
            // ----------------------------------------
            $query = Project::query()
                ->where('Project.IsDelete', false)
                ->with('status')
            
                // COUNT TASK
                ->select('Project.*')
                ->selectSub(function ($q) {
                    $q->from('ProjectTask')
                      ->whereColumn('ProjectTask.ProjectID', 'Project.ProjectID')
                      ->where('ProjectTask.IsDelete', false)
                      ->selectRaw('COUNT(*)');
                }, 'total_task')
            
                // SUM PROGRESS WITH RULE
                ->selectSub(function ($q) {
                    $q->from('ProjectTask')
                      ->whereColumn('ProjectTask.ProjectID', 'Project.ProjectID')
                      ->where('ProjectTask.IsDelete', false)
                      ->selectRaw("
                        COALESCE(SUM(
                            CASE 
                                WHEN ProgressBar = 100 AND IsCheck = 0 THEN 99
                                ELSE ProgressBar
                            END
                        ), 0)
                      ");
                }, 'total_progress');
            // ----------------------------------------
            // FILTER FOR NON ADMIN
            // ----------------------------------------
            if (!$isAdmin) {
                $query->whereIn('ProjectID', function ($q) use ($authUserId) {
                    $q->select('ProjectID')
                        ->from('ProjectMember')
                        ->where('UserID', $authUserId)
                        ->where('IsActive', true);
                });
            }
            // =========================
            // SEARCH FILTER
            // =========================
            if ($request->filled('Search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('Project.ProjectName', 'like', '%' . $request->Search . '%')
                    ->orWhere('Project.ProjectDescription', 'like', '%' . $request->Search . '%');
                });
            }
            // =========================
            // DATE FILTER (OVERLAP)
            // =========================
            // if ($request->filled('StartDate')) {
            //     $query->whereDate('Project.EndDate', '>=', $request->StartDate);
            // }
            // if ($request->filled('EndDate')) {
            //     $query->whereDate('Project.StartDate', '<=', $request->EndDate);
            // }
            if ($request->filled('StartDate') && $request->filled('EndDate')) {
                $query->where(function ($q) use ($request) {
                    $q->whereDate('Project.StartDate', '<=', $request->StartDate)
                      ->whereDate('Project.EndDate', '>=', $request->EndDate);
                });
            }
            // =========================
            // PAGINATION
            // =========================
            $projects = $query
                ->orderBy('Project.AtTimeStamp', 'DESC')
                ->paginate($perPage, ['*'], 'page', $page);
            // =========================
            // TRANSFORM RESPONSE
            // =========================
            $data = $projects->getCollection()->transform(function ($project) use ($authUserId, $isAdmin) {
                $member = null;
                if (!$isAdmin) {
                    $member = ProjectMember::where('ProjectID', $project->ProjectID)
                        ->where('UserID', $authUserId)
                        ->where('IsActive', true)
                        ->first();
                }
                // =========================
                // CALCULATE FINAL PROGRESS
                // =========================
                $projectProgress = 0;
                if ($project->total_task > 0) {
                    $projectProgress = round(
                        $project->total_progress / $project->total_task,
                        2
                    );
                }
                return 
                [
                    'ProjectID'          => $project->ProjectID,
                    'ProjectName'        => $project->ProjectName,
                    'ProjectDescription'=> $project->ProjectDescription,
                    'StartDate'          => $project->StartDate,
                    'EndDate'            => $project->EndDate,
                    'PriorityCode'       => $project->PriorityCode,
                    'Progress'            => $projectProgress,
                    'Status' => [
                        'ProjectStatusCode'  => $project->status->ProjectStatusCode ?? null,
                        'ProjectStatusName'  => ProjectStatus::nameFromCode($project->status->ProjectStatusCode ?? null),
                        'TotalMember'        => $project->status->TotalMember ?? 0,
                        'TotalTask'          => $project->status->TotalTask ?? 0,
                        'TotalExpense'       => $project->status->TotalExpense ?? 0,
                        'AccumulatedExpense' => $project->status->AccumulatedExpense ?? 0,
                    ],
                    // Role info for FE
                    'role' => $isAdmin
                        ? 'ADMIN'
                        : ($member?->IsOwner ? 'OWNER' : 'MEMBER'),
                    'is_owner' => $isAdmin ? true : ($member?->IsOwner ?? false),
                ];
            });
            return response()->json([
                'success' => true,
                'role'    => $isAdmin ? 'ADMIN' : 'USER',
                'data'    => $data,
                'meta'    => [
                    'current_page' => $projects->currentPage(),
                    'per_page'     => $projects->perPage(),
                    'total'        => $projects->total(),
                    'last_page'    => $projects->lastPage(),
                ]
            ], 200);
            // // ----------------------------------------
            // // FETCH PROJECTS
            // // ----------------------------------------
            // $projects = $query  
            //     ->orderBy('AtTimeStamp', 'desc')
            //     ->get();
            // // ----------------------------------------
            // // MAP RESPONSE
            // // ----------------------------------------
            // $data = $projects->map(function ($project) use ($authUserId, $isAdmin) {
            //     $member = null;
            //     if (!$isAdmin) {
            //         $member = ProjectMember::where('ProjectID', $project->ProjectID)
            //             ->where('UserID', $authUserId)
            //             ->where('IsActive', true)
            //             ->first();
            //     }
            //     return [
            //         'ProjectID' => $project->ProjectID,
            //         'ProjectDescription' => $project->ProjectDescription,
            //         'ProjectName' => $project->ProjectName,
            //         'StartDate' => $project->StartDate,
            //         'EndDate' => $project->EndDate,
            //         'PriorityCode' => $project->PriorityCode,
            //         'Status' => [
            //             'ProjectStatusCode' => $project->status->ProjectStatusCode ?? null,
            //             'TotalMember' => $project->status->TotalMember ?? 0,
            //             'TotalTask' => $project->status->TotalTask ?? 0,
            //             'TotalExpense' => $project->status->TotalExpense ?? 0,
            //             'AccumulatedExpense' => $project->status->AccumulatedExpense ?? 0,
            //         ],
            //         // Role info for FE
            //         'role' => $isAdmin
            //             ? 'ADMIN'
            //             : ($member?->IsOwner ? 'OWNER' : 'MEMBER'),
            //         'is_owner' => $isAdmin ? true : ($member?->IsOwner ?? false),
            //     ];
            // });
            // return response()->json([
            //     'success' => true,
            //     'data' => $data,
            // ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch project list',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Project Detail
     * 
     * GET /projects/{id}?include=members,tasks,expenses
     * Optional filters:
     * - member_status: active | inactive | all
     * - task_status: active | deleted | all
     * - expense_status: active | deleted | all
     */
    public function show(Request $request, $projectId)
    {
        try {
            $authUser   = $request->auth_user;
            $authUserId = $request->auth_user_id;
            $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
            $includeParam = $request->query('include');
            $includes = $includeParam
                ? array_map('trim', explode(',', $includeParam))
                : ['members', 'tasks', 'expenses', 'mini_goals'];

            $memberStatus  = $request->query('member_status', 'active');
            $taskStatus    = $request->query('task_status', 'active');
            $expenseStatus = $request->query('expense_status', 'active');
            $relations = ['status'];


            // =======================
            // MEMBERS
            // =======================
            if (in_array('members', $includes)) {
                $relations['members'] = function ($q) use ($memberStatus) {
                    if ($memberStatus === 'active') {
                        $q->where('IsActive', true);
                    } elseif ($memberStatus === 'inactive') {
                        $q->where('IsActive', false);
                    }
                    // all → no filter
                };
                $relations[] = 'members.user';
            }
            /**
             * "success":false,"message":"Failed to fetch project detail","error":"Call to undefined relationship [user] on model [App\\Models\\ProjectMember]."}
             */

            // =======================
            // TASKS
            // =======================
            if (in_array('tasks', $includes)) {
                $isOwner = ProjectMember::where('ProjectID', $projectId)
                    ->where('UserID', $authUserId)
                    ->where('IsOwner', true)
                    ->where('IsActive', true)
                    ->exists();

                $relations['tasks'] = function ($q) use (
                    $taskStatus,
                    $isAdmin,
                    $isOwner,
                    $authUserId
                ) {
                    // Status filter
                    if ($taskStatus === 'active') {
                        $q->where('IsDelete', false);
                    } elseif ($taskStatus === 'deleted') {
                        $q->where('IsDelete', true);
                    }

                    // 🔐 EMPLOYEE: hanya task yang di-assign ke dia
                    if (!$isAdmin && !$isOwner) {
                        $q->whereExists(function ($sub) use ($authUserId) {
                            $sub->selectRaw(1)
                                ->from('ProjectAssignMember as pam')
                                ->join(
                                    'ProjectMember as pm',
                                    'pm.ProjectMemberID',
                                    '=',
                                    'pam.ProjectMemberID'
                                )
                                ->whereColumn(
                                    'pam.ProjectTaskID',
                                    'ProjectTask.ProjectTaskID'
                                )
                                ->where('pm.UserID', $authUserId)
                                ->where('pm.IsActive', true);
                        });
                    }
                };

                $relations[] = 'tasks.files';
                $relations[] = 'tasks.assignments.member.user';
            }

            // =======================
            // EXPENSES
            // =======================
            if (in_array('expenses', $includes)) {
                $relations['expenses'] = function ($q) use ($expenseStatus) {
                    if ($expenseStatus === 'active') {
                        $q->where('IsDelete', false);
                    } elseif ($expenseStatus === 'deleted') {
                        $q->where('IsDelete', true);
                    }
                    // all → no filter
                };
                $relations[] = 'expenses.files';
            }

            // =======================
            // MINI GOALS
            // =======================
            if (in_array('mini_goals', $includes)) {
                $relations['miniGoals'] = function ($q) {
                    $q->where('IsDelete', false)
                    ->orderBy('SequenceNo');
                };
            }

            // ----------------------------------------
            // Fetch project
            // ----------------------------------------
            $project = Project::with($relations)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }

            // ----------------------------------------
            // Clean response payload
            // ----------------------------------------
            $data = $project->toArray();

            if (!in_array('members', $includes)) {
                unset($data['members']);
            }

            if (!in_array('tasks', $includes)) {
                unset($data['tasks']);
            }

            if (!in_array('expenses', $includes)) {
                unset($data['expenses']);
            }
            if (!in_array('mini_goals', $includes)) {
            unset($data['mini_goals']);
            }

            $data['document'] = $project->DocumentPath ? [
                'path' => $project->DocumentPath,
                'url'  => $project->DocumentUrl,
                'original_path' => $project->DocumentOriginalPath,
                'original_url'  => $project->DocumentOriginalUrl,
            ] : null;


            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);

            // $project = Project::with([
            //     'status',
            //     'members' => function ($q) {
            //         $q->where('IsActive', true);
            //     },
            //     'members.user',
            //     'tasks' => function ($q) {
            //         $q->where('IsDelete', false);
            //     },
            //     'tasks.files' => function ($q) {
            //         $q->where('IsDelete', false);
            //     },
            //     'tasks.assignments.member.user',
            //     'expenses' => function ($q) {
            //         $q->where('IsDelete', false);
            //     },
            //     'expenses.files' => function ($q) {
            //         $q->where('IsDelete', false);
            //     },
            // ])->where('ProjectID', $projectId)
            //     ->where('IsDelete', false)
            //     ->first();

            // if (!$project) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Project not found',
            //     ], 404);
            // }

            // return response()->json([
            //     'success' => true,
            //     'data' => $project,
            // ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch project detail',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update Project
     */
    public function update(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'ProjectDescription' => 'nullable|string',
            'ProjectCategoryID' => 'nullable|integer',
            'ProjectName' => 'nullable|string',
            'CurrencyCode' => 'nullable|string|max:3',
            'BudgetAmount' => 'nullable|numeric',
            'StartDate' => 'nullable|date_format:Y-m-d',
            'EndDate' => 'nullable|date_format:Y-m-d|after_or_equal:StartDate',
            'PriorityCode' => 'nullable|integer|in:1,2,3',
            'ProjectStatusCode' => 'nullable|string|max:2|in:00,10,11,12,99',
            'ProjectStatusReason' => 'nullable|string|max:200',
            // =====================
            // PROJECT FILE (OPTIONAL, STRICT)
            // =====================
            'file' => 'nullable|array',
            'file.original_filename' => 'required_with:file|string|max:255',
            'file.original_content_type' => 'required_with:file|string|max:100',
            'file.original_file_size' => 'nullable|integer|min:1',
            'file.has_converted_pdf' => 'required_with:file|boolean',
            'delete_file' => 'nullable|boolean',

            'file.converted_filename' => 'required_if:file.has_converted_pdf,true|string|max:255',
            'file.converted_file_size' => 'nullable|integer|min:1',
            'Reason' => 'required|string|max:200'
        ]);

        if ($request->boolean('delete_file') && $request->has('file')) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload and delete file at the same time',
            ], 422);
        }

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
            $user = $request->auth_user;

            $uploadResult = [];

            $project = Project::where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }

            $status = ProjectStatus::where('ProjectID', $projectId)->first();
            $currentStatusCode = $status?->ProjectStatusCode;

            if ($currentStatusCode === '00') {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status (VOID) cannot be updated',
                ], 409);
            }

            if ($currentStatusCode === '99') {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status (COMPLETED) cannot be updated',
                ], 409);
            }

            if ($request->input('ProjectStatusCode') === '99') {
                return response()->json([
                    'success' => false,
                    'message' => 'Use completed endpoint to set project status as completed',
                ], 422);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can update project',
                ], 403);
            }

            $oldData = $project->toArray();

            // Update Project
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('ProjectCategoryID')) {
                $updateData['ProjectCategoryID'] = $request->ProjectCategoryID;
            }
            if ($request->has('ProjectDescription')) {
                $updateData['ProjectDescription'] = $request->ProjectDescription;
            }
            if ($request->has('ProjectName')) {
                $updateData['ProjectName'] = $request->ProjectName;
            }
            if ($request->has('CurrencyCode')) {
                $updateData['CurrencyCode'] = $request->CurrencyCode;
            }
            if ($request->has('BudgetAmount')) {
                $updateData['BudgetAmount'] = $request->BudgetAmount;
            }
            if ($request->has('StartDate')) {
                $updateData['StartDate'] = $request->StartDate;
            }
            if ($request->has('EndDate')) {
                $updateData['EndDate'] = $request->EndDate;
            }
            if ($request->has('PriorityCode')) {
                $updateData['PriorityCode'] = $request->PriorityCode;
            }
            // =====================
            // PROJECT FILE HANDLING
            // =====================

            // CASE 1: DELETE FILE
            if ($request->boolean('delete_file')) {
                $project->update([
                    'DocumentPath' => null,
                    'DocumentUrl' => null,
                    'DocumentOriginalPath' => null,
                    'DocumentOriginalUrl' => null,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                ]);
            }

            // CASE 2: UPDATE / REPLACE FILE
            elseif ($request->has('file')) {
                $file = $request->input('file');

                // upload ke MinIO
                $uploadResult = $this->handleProjectFileEdit(
                    $projectId,
                    $request->input('file'),
                    false, // bukan delete
                    $authUserId,
                    $timestamp
                );

                // $updateData['DocumentPath'] = $uploadResult['DocumentPath'];
                // $updateData['DocumentUrl'] = $uploadResult['DocumentUrl'];
                // $updateData['DocumentOriginalPath'] = $uploadResult['DocumentOriginalPath'];
                // $updateData['DocumentOriginalUrl'] = $uploadResult['DocumentOriginalUrl'];
            }


            $project->update($updateData);

            // Update Project Status if provided
            if ($request->has('ProjectStatusCode')) {
                ProjectStatus::where('ProjectID', $projectId)->update([
                    'ProjectStatusCode' => $request->ProjectStatusCode,
                    'ProjectStatusReason' => $request->ProjectStatusReason ?? null,
                ]);

                // If HOLD (12) -> ON-PROGRESS (11), sync task deadlines that still need completion.
                if (
                    $currentStatusCode === '12'
                    && $request->ProjectStatusCode === '11'
                ) {
                    $targetEndDate = $request->input('EndDate', $project->fresh()->EndDate);
                    ProjectTask::where('ProjectID', $projectId)
                        ->where('IsDelete', false)
                        ->where(function ($q) {
                            $q->where('ProgressBar', '<', 100)
                                ->orWhere(function ($qq) {
                                    $qq->where('ProgressBar', '>=', 100)
                                        ->where('IsCheck', false);
                                });
                        })
                        ->update([
                            'EndDate' => $targetEndDate,
                            'AtTimeStamp' => $timestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'U',
                        ]);
                }
            }

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Project',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $project->fresh()->toArray(),
                ]),
                'Note' => $request->Reason ?? 'Project updated'
            ]);

            DB::commit();
            $projectData = $project->fresh(['status'])->toArray();
            if (!empty($uploadResult)) {
                $projectData['file_upload'] = $uploadResult;
            }

            return response()->json([
                'success' => true,
                'message' => 'Project updated successfully',
                'data' => $projectData,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete Project
     */
    public function completeProject(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'ProjectStatusReason' => 'nullable|string|max:200',
            'Reason' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $timestamp = Carbon::now()->timestamp;
            $user = $request->auth_user;

            $project = Project::where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can complete project',
                ], 403);
            }

            $incompleteOrUncheckedCount = ProjectTask::where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->where(function ($q) {
                    $q->where('ProgressBar', '<', 100)
                        ->orWhere('IsCheck', false);
                })
                ->count();

            if ($incompleteOrUncheckedCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project cannot be completed because some tasks are incomplete or unchecked',
                    'remaining_task_count' => $incompleteOrUncheckedCount,
                ], 422);
            }

            ProjectStatus::where('ProjectID', $projectId)->update([
                'ProjectStatusCode' => '99',
                'ProjectStatusReason' => $request->ProjectStatusReason ?? 'Project completed',
            ]);

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'ProjectStatus',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'ProjectStatusCode' => '99',
                    'ProjectStatusReason' => $request->ProjectStatusReason ?? 'Project completed',
                ]),
                'Note' => $request->Reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Project marked as completed',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Project (Soft Delete)
     */
    public function destroy(Request $request, $projectId)
    {
         $validator = Validator::make($request->all(), [
            'Reason' => 'required|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            $project = Project::where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can delete project',
                ], 403);
            }

            $oldData = $project->toArray();

            // Soft delete project
            $project->update([
                'IsDelete' => true,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
            ]);

            // Update status to VOID
            ProjectStatus::where('ProjectID', $projectId)->update([
                'ProjectStatusCode' => '00',
                'ProjectStatusReason' => 'Project deleted',
            ]);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'Project',
                'ReferenceRecordID' => $projectId,
                'Data' => json_encode($oldData),
                'Note' => $request->Reason ?? 'Project deleted (soft delete)'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Project deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add Project Member
     */
    public function addMember(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'UserID' => 'required|integer|exists:User,UserID',
            'IsOwner' => 'required|boolean',
            'Title' => 'nullable|string|max:200',
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can add members',
                ], 403);
            }

            // Check if member already exists
            $existingMember = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $request->UserID)
                ->first();

            if ($existingMember && $existingMember->IsActive) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this project',
                ], 422);
            }

            // If member exists but inactive, reactivate
            if ($existingMember && !$existingMember->IsActive) {
                $existingMember->update([
                    'IsActive' => true,
                    'IsOwner' => $request->IsOwner,
                    'Title' => $request->Title,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                ]);

                $member = $existingMember;
                $action = 'reactivated';
            } else {
                // Create new member
                $memberId = $timestamp . random_numbersu(5);
                $member = ProjectMember::create([
                    'ProjectMemberID' => $memberId,
                    'ProjectID' => $projectId,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'UserID' => $request->UserID,
                    'IsActive' => true,
                    'IsOwner' => $request->IsOwner,
                    'Title' => $request->Title,
                ]);
                $action = 'added';
            }

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => $action == 'added' ? 'I' : 'U',
                'ReferenceTable' => 'ProjectMember',
                'ReferenceRecordID' => $member->ProjectMemberID,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'UserID' => $request->UserID,
                    'IsOwner' => $request->IsOwner,
                    'Title' => $request->Title,
                ]),
                'Note' => "Project member {$action}"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Member {$action} successfully",
                'data' => $member,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add member',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Edit Project Member
     */
    public function updateMember(Request $request, $projectId, $memberId)
    {
        $validator = Validator::make($request->all(), [
            'IsOwner' => 'nullable|boolean',
            'Title' => 'nullable|string|max:200',
            'IsActive' => 'nullable|boolean',
            'Reason' => 'required'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can edit members',
                ], 403);
            }

            $member = ProjectMember::where('ProjectMemberID', $memberId)
                ->where('ProjectID', $projectId)
                ->first();

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found',
                ], 404);
            }

            $oldData = $member->toArray();

            // =========================
            // 🔥 BUSINESS RULE CHECK
            // =========================
            if ($request->has('IsActive') && $request->IsActive === false) {

                $hasBlockingTask = ProjectAssignMember::where(
                        'ProjectAssignMember.ProjectMemberID',
                        $memberId
                    )
                    ->join(
                        'ProjectTask',
                        'ProjectTask.ProjectTaskID',
                        '=',
                        'ProjectAssignMember.ProjectTaskID'
                    )
                    ->where('ProjectTask.ProjectID', $projectId)
                    ->where('ProjectTask.IsDelete', false)
                    ->where('ProjectTask.ProgressBar', '<', 100)
                    ->exists();

                if ($hasBlockingTask) {
                    return response()->json([
                        'success' => false,
                        'message' =>
                            'Member cannot be deactivated because there are unfinished active tasks assigned',
                    ], 409);
                }
            }

            // Update member
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('IsOwner')) {
                $updateData['IsOwner'] = $request->IsOwner;
            }
            if ($request->has('Title')) {
                $updateData['Title'] = $request->Title;
            }
            if ($request->has('IsActive')) {
                $updateData['IsActive'] = $request->IsActive;
            }

            $member->update($updateData);

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'ProjectMember',
                'ReferenceRecordID' => $memberId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $member->fresh()->toArray(),
                ]),
                'Note' => $request->Reasons ?? 'Project member updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Member updated successfully',
                'data' => $member->fresh(),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update member',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add Project Task
     */
    public function addTask(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'ParentProjectTaskID' => 'nullable|integer',
            'SequenceNo' => 'nullable|integer',
            'PriorityCode' => 'required|integer|in:1,2,3',
            'TaskDescription' => 'required|string|max:200',
            'StartDate' => 'required|date_format:Y-m-d',
            'EndDate' => 'required|date_format:Y-m-d|after_or_equal:StartDate',
            'ProgressBar' => 'nullable|numeric|min:0|max:100', // NEW   
            'Note' => 'nullable|string',

            // Task Files (opsional)
            'files' => 'nullable|array',
            'files.*.original_filename' => 'required|string|max:255',
            'files.*.original_content_type' => 'required|string|max:100',
            'files.*.original_file_size' => 'nullable|integer|min:1',
            'files.*.has_converted_pdf' => 'required|boolean',
            'files.*.converted_filename' => 'nullable|required_if:files.*.has_converted_pdf,true|string|max:255',
            'files.*.converted_file_size' => 'nullable|integer|min:1',

            // Assigned Members (based on UserID)
            'assignedMembers' => 'nullable|array',
            'assignedMembers.*' => 'integer|exists:User,UserID',
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is THE ONLY owner
            $ownerCheck = $this->checkSingleOwner($projectId, $authUserId);
            if (!$ownerCheck['is_owner'] && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => $ownerCheck['message'],
                ], 403);
            }

            // Validate task dates are within project dates
            $dateValidation = $this->validateTaskDates(
                $projectId,
                $request->StartDate,
                $request->EndDate
            );

            if (!$dateValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $dateValidation['message'],
                ], 422);
            }

            // Calculate ProgressCode based on ProgressBar + IsCheck
            $progressBar = $request->input('ProgressBar', 0);
            $progressCode = $this->calculateProgressCode(
                $progressBar,
                false,
                $request->EndDate,
                $request->EndDate
            );


            // Create task
            $taskId = $timestamp . random_numbersu(5);
            $task = ProjectTask::create([
                'ProjectTaskID' => $taskId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ParentProjectTaskID' => $request->ParentProjectTaskID,
                'SequenceNo' => $request->SequenceNo,
                'PriorityCode' => $request->PriorityCode,
                'TaskDescription' => $request->TaskDescription,
                'StartDate' => $request->StartDate,
                'EndDate' => $request->EndDate,
                'ProgressCode' => $progressCode,
                'ProgressBar' => $progressBar,
                'Note' => $request->Note,
                'IsDelete' => false,
                'IsCheck' => false,
            ]);

            $taskFileUrls = [];

            // Generate Presigned URLs for Task Files (if any)
            if ($request->has('files') && !empty($request->files)) {
                foreach ($request->files as $fileData) {
                    $fileResult = $this->handleTaskFileUpload(
                        $projectId,
                        $taskId,
                        $fileData,
                        $authUserId,
                        $timestamp
                    );

                    $taskFileUrls[] = $fileResult;
                }
            }

            // Assign Members to Task (if any) - based on UserID
            $assignedMembers = [];
            $assignedUserIdsForNotification = [];
            if ($request->has('assignedMembers') && !empty($request->assignedMembers)) {
                // Get member map
                $members = ProjectMember::where('ProjectID', $projectId)
                    ->whereIn('UserID', $request->assignedMembers)
                    ->where('IsActive', true)
                    ->get()
                    ->keyBy('UserID');

                foreach ($request->assignedMembers as $assignedUserId) {
                    if (isset($members[$assignedUserId])) {
                        $assignTimestamp = Carbon::now()->timestamp;
                        $assignId = $assignTimestamp . random_numbersu(5);

                        ProjectAssignMember::create([
                            'ProjectAssignMemberID' => $assignId,
                            'AtTimeStamp' => $assignTimestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'I',
                            'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                            'ProjectTaskID' => $taskId,
                        ]);

                        $assignedMembers[] = [
                            'UserID' => $assignedUserId,
                            'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                        ];
                        $assignedUserIdsForNotification[] = (int) $assignedUserId;
                    }
                }
            }

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'ProjectTask',
                'ReferenceRecordID' => $taskId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'TaskDescription' => $request->TaskDescription,
                    'PriorityCode' => $request->PriorityCode,
                    'ProgressCode' => $progressCode,
                    'ProgressBar' => $progressBar,
                    'StartDate' => $request->StartDate,
                    'EndDate' => $request->EndDate,
                    'TotalFiles' => count($taskFileUrls),
                    'AssignedMembers' => $assignedMembers,
                ]),
                'Note' => 'Project task added'
            ]);

            DB::commit();

            if (!empty($assignedUserIdsForNotification)) {
                $this->sendAssigneeNotification(
                    Project::find($projectId),
                    $taskId,
                    $task->TaskDescription,
                    $assignedUserIdsForNotification,
                    'assigned'
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Task added successfully',
                'data' => [
                    'task' => $task,
                    'files' => $taskFileUrls,
                    'assignedMembers' => $assignedMembers,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit Project Task
     */
    public function updateTask(Request $request, $projectId, $taskId)
    {
        $validator = Validator::make($request->all(), [
            'ParentProjectTaskID' => 'nullable|integer',
            'SequenceNo' => 'nullable|integer',
            'PriorityCode' => 'nullable|integer|in:1,2,3',
            'TaskDescription' => 'nullable|string|max:200',
            'StartDate' => 'nullable|date_format:Y-m-d',
            'EndDate' => 'nullable|date_format:Y-m-d',
            'ProgressBar' => 'nullable|numeric|min:0|max:100',
            'Note' => 'nullable|string',
            'IsCheck' => 'nullable|boolean',

            // Upload files
            'files' => 'nullable|array',
            'files.*.original_filename' => 'required|string|max:255',
            'files.*.original_content_type' => 'required|string|max:100',
            'files.*.original_file_size' => 'nullable|integer|min:1',
            'files.*.has_converted_pdf' => 'required|boolean',
            'files.*.converted_filename' => 'nullable|required_if:files.*.has_converted_pdf,true|string|max:255',
            'files.*.converted_file_size' => 'nullable|integer|min:1',

            // Delete files (OWNER only)
            'delete_files' => 'nullable|array',
            'delete_files.*' => 'integer',

            // Re-assign (OWNER only)
            'assignedMembers' => 'nullable|array',
            'assignedMembers.*' => 'integer|exists:User,UserID',

            'Reason' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $timestamp  = Carbon::now()->timestamp;
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // =========================
            // AUTH CHECK
            // =========================
            $access = $this->canUpdateTask($projectId, $taskId, $authUserId, $user);

            if (!$access['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to update this task',
                ], 403);
            }

            // =========================
            // LOAD TASK
            // =========================
            $task = ProjectTask::where('ProjectTaskID', $taskId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found',
                ], 404);
            }

            $oldData = $task->toArray();
            $oldProgressBar = (float) $task->ProgressBar;
            $oldAssignedUserIds = ProjectAssignMember::query()
                ->join('ProjectMember', 'ProjectMember.ProjectMemberID', '=', 'ProjectAssignMember.ProjectMemberID')
                ->where('ProjectAssignMember.ProjectTaskID', $taskId)
                ->pluck('ProjectMember.UserID')
                ->map(static fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            // =========================
            // FILTER INPUT (🔥 SINGLE SOURCE)
            // =========================
            $filteredInput = $this->filterTaskUpdateData(
                $request->all(),
                $access['role']
            );

            if ($access['role'] === 'ASSIGNEE' && empty($filteredInput)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No permitted fields to update',
                ], 403);
            }

            if (
                $access['role'] === 'OWNER'
                && array_key_exists('IsCheck', $filteredInput)
                && (bool) $filteredInput['IsCheck'] === false
                && $oldProgressBar >= 100
                && !array_key_exists('ProgressBar', $filteredInput)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'When rejecting checked task at 100% progress, ProgressBar input is required',
                ], 422);
            }

            // =========================
            // DATE VALIDATION
            // =========================
            $newStartDate = $filteredInput['StartDate'] ?? $task->StartDate;
            $newEndDate   = $filteredInput['EndDate'] ?? $task->EndDate;

            $dateValidation = $this->validateTaskDates(
                $projectId,
                $newStartDate,
                $newEndDate
            );

            if (!$dateValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $dateValidation['message'],
                ], 422);
            }

            // =========================
            // BUILD UPDATE DATA
            // =========================
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            foreach ($filteredInput as $field => $value) {
                $updateData[$field] = $value;
            }

            // ProgressCode auto-calc
            if (
                array_key_exists('ProgressBar', $filteredInput)
                || array_key_exists('IsCheck', $filteredInput)
                || array_key_exists('EndDate', $filteredInput)
            ) {
                $progressBarForCalc = array_key_exists('ProgressBar', $filteredInput)
                    ? (float) $filteredInput['ProgressBar']
                    : (float) $task->ProgressBar;
                $isCheckForCalc = array_key_exists('IsCheck', $filteredInput)
                    ? (bool) $filteredInput['IsCheck']
                    : (bool) $task->IsCheck;

                $updateData['ProgressCode'] = $this->calculateProgressCode(
                    $progressBarForCalc,
                    $isCheckForCalc,
                    $task->EndDate,
                    $newEndDate
                );
            }

            $task->update($updateData);

            // =========================
            // FILE UPLOAD (OWNER + ASSIGNEE)
            // =========================
            $uploadedFiles = [];

            if (!empty($request->input('files'))) {

                if (
                    $access['role'] === 'ASSIGNEE'
                    && !array_key_exists('ProgressBar', $filteredInput)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Assignee must update progress when uploading work proof',
                    ], 403);
                }

                foreach ($request->input('files') as $fileData) {
                    $uploadedFiles[] = $this->handleTaskFileUpload(
                        $projectId,
                        $taskId,
                        array_merge($fileData, [
                            'uploaded_by_role' => $access['role'],
                            'file_purpose' => $access['role'] === 'ASSIGNEE'
                                ? 'WORK_PROOF'
                                : 'ATTACHMENT',
                        ]),
                        $authUserId,
                        $timestamp
                    );
                }
            }

            // =========================
            // DELETE FILE (OWNER ONLY)
            // =========================
            if (
                $access['role'] === 'OWNER'
                && !empty($request->delete_files)
            ) {
                ProjectTaskFile::whereIn(
                    'ProjectTaskFileID',
                    $request->delete_files
                )
                    ->where('ProjectTaskID', $taskId)
                    ->update([
                        'IsDelete' => true,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'D',
                    ]);
            }

            // =========================
            // UPDATE ASSIGNED MEMBERS (OWNER ONLY)
            // =========================
            $assignedMembers = [];
            $newlyAssignedUserIds = [];
            
            if ($access['role'] === 'OWNER' && $request->has('assignedMembers')) {
            
                // 🔥 STEP 1: hapus semua assignment lama
                ProjectAssignMember::where('ProjectTaskID', $taskId)->delete();
            
                // 🔥 STEP 2: insert assignment baru (jika ada)
                if (!empty($request->assignedMembers)) {
            
                    // Ambil project member valid & aktif
                    $members = ProjectMember::where('ProjectID', $projectId)
                        ->whereIn('UserID', $request->assignedMembers)
                        ->where('IsActive', true)
                        ->get()
                        ->keyBy('UserID');
            
                    foreach ($request->assignedMembers as $assignedUserId) {
            
                        if (!isset($members[$assignedUserId])) {
                            continue; // skip user invalid / inactive
                        }
            
                        $assignId = $timestamp . random_numbersu(5);
            
                        ProjectAssignMember::create([
                            'ProjectAssignMemberID' => $assignId,
                            'AtTimeStamp' => $timestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'I',
                            'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                            'ProjectTaskID' => $taskId,
                        ]);
            
                        $assignedMembers[] = [
                            'UserID' => $assignedUserId,
                            'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                        ];
                    }
                }

                $newAssignedUserIds = array_values(array_unique(array_map(
                    static fn($member) => (int) $member['UserID'],
                    $assignedMembers
                )));
                $newlyAssignedUserIds = array_values(
                    array_diff($newAssignedUserIds, $oldAssignedUserIds)
                );
            }


            // =========================
            // AUDIT LOG
            // =========================
            $reason = $request->Reason
                ?? ($access['role'] === 'ASSIGNEE'
                    ? 'Progress updated with work proof'
                    : 'Project task updated');

            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'ProjectTask',
                'ReferenceRecordID' => $taskId,
                'Data' => json_encode([
                    'role' => $access['role'],
                    'changed_fields' => array_keys($filteredInput),
                    'uploaded_files' => count($uploadedFiles),
                    'old' => $oldData,
                    'new' => $task->fresh()->toArray(),
                ]),
                'Note' => $reason,
            ]);

            $this->updateProjectStatus($projectId);

            $sendApprovalNotificationToOwner = (
                $access['role'] === 'ASSIGNEE'
                && array_key_exists('ProgressBar', $filteredInput)
                && (float) $filteredInput['ProgressBar'] >= 100
                && $oldProgressBar < 100
            );

            $sendRejectedNotificationToAssignee = (
                $access['role'] === 'OWNER'
                && array_key_exists('IsCheck', $filteredInput)
                && (bool) $filteredInput['IsCheck'] === false
                && $oldProgressBar >= 100
            );

            DB::commit();

            if (!empty($newlyAssignedUserIds)) {
                $this->sendAssigneeNotification(
                    Project::find($projectId),
                    $taskId,
                    $task->fresh()->TaskDescription,
                    $newlyAssignedUserIds,
                    'assigned'
                );
            }

            if ($sendRejectedNotificationToAssignee) {
                $assigneeUserIds = ProjectAssignMember::query()
                    ->join('ProjectMember', 'ProjectMember.ProjectMemberID', '=', 'ProjectAssignMember.ProjectMemberID')
                    ->where('ProjectAssignMember.ProjectTaskID', $taskId)
                    ->pluck('ProjectMember.UserID')
                    ->map(static fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if (!empty($assigneeUserIds)) {
                    $this->sendAssigneeNotification(
                        Project::find($projectId),
                        $taskId,
                        $task->fresh()->TaskDescription,
                        $assigneeUserIds,
                        'rejected'
                    );
                }
            }

            if ($sendApprovalNotificationToOwner) {
                $this->sendOwnerApprovalNotification(
                    Project::find($projectId),
                    $taskId,
                    $task->fresh()->TaskDescription
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => [
                    'task' => $task->fresh(['files', 'assignments']),
                    'uploaded_files' => $uploadedFiles,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function oldupdateTask(Request $request, $projectId, $taskId)
    {
        $validator = Validator::make($request->all(), [
            'ParentProjectTaskID' => 'nullable|integer',
            'SequenceNo' => 'nullable|integer',
            'PriorityCode' => 'nullable|integer|in:1,2,3',
            'TaskDescription' => 'nullable|string|max:200',
            'StartDate' => 'nullable|date_format:Y-m-d',
            'EndDate' => 'nullable|date_format:Y-m-d',
            'ProgressBar' => 'nullable|numeric|min:0|max:100', // NEW
            'Note' => 'nullable|string',
            'IsCheck' => 'nullable|boolean',

            // New Files to Add
            'files' => 'nullable|array',
            'files.*.original_filename' => 'required|string|max:255',
            'files.*.original_content_type' => 'required|string|max:100',
            'files.*.original_file_size' => 'nullable|integer|min:1',
            'files.*.has_converted_pdf' => 'required|boolean',
            'files.*.converted_filename' => 'nullable|required_if:files.*.has_converted_pdf,true|string|max:255',
            'files.*.converted_file_size' => 'nullable|integer|min:1',

            // Files to Delete
            'delete_files' => 'nullable|array',
            'delete_files.*' => 'integer',

            // Update Assigned Members (based on UserID)
            'assignedMembers' => 'nullable|array',
            'assignedMembers.*' => 'integer|exists:User,UserID',
            'Reason' => 'nullable|string'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            // $ownerCheck = $this->checkSingleOwner($projectId, $authUserId);
            // if (!$ownerCheck['is_owner']) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => $ownerCheck['message'],
            //     ], 403);
            // }

            $access = $this->canUpdateTask($projectId, $taskId, $authUserId, $user);

            if (!$access['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to update this task',
                ], 403);
            }



            $task = ProjectTask::where('ProjectTaskID', $taskId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found',
                ], 404);
            }

            $oldData = $task->toArray();

             // ----------------------------
            // FILTER INPUT BY ROLE
            // ----------------------------
            $input = $this->filterTaskUpdateData($request->all(), $access['role']);

            if ($access['role'] === 'ASSIGNEE' && empty($input)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No permitted fields to update',
                ], 403);
            }

            // ----------------------------
            // DATE VALIDATION (OWNER ONLY)
            // ----------------------------
            if ($access['role'] === 'OWNER') {
                $newStartDate = $request->input('StartDate', $task->StartDate);
                $newEndDate   = $request->input('EndDate', $task->EndDate);

                $dateValidation = $this->validateTaskDates($projectId, $newStartDate, $newEndDate);
                if (!$dateValidation['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $dateValidation['message'],
                    ], 422);
                }
            }

            // Update task
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

             foreach ($input as $field => $value) {
                $updateData[$field] = $value;
            }

            // ProgressCode auto-calc
            if (array_key_exists('ProgressBar', $input)) {
                $progressBarForCalc = (float) $input['ProgressBar'];
                $isCheckForCalc = array_key_exists('IsCheck', $input)
                    ? (bool) $input['IsCheck']
                    : (bool) $task->IsCheck;
                $updateData['ProgressCode'] = $this->calculateProgressCode(
                    $progressBarForCalc,
                    $isCheckForCalc,
                    $task->EndDate,
                    $task->EndDate
                );
            }

            $task->update($updateData);


            // Validate dates if changed
            $newStartDate = $request->input('StartDate', $task->StartDate);
            $newEndDate = $request->input('EndDate', $task->EndDate);

            $dateValidation = $this->validateTaskDates($projectId, $newStartDate, $newEndDate);
            if (!$dateValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $dateValidation['message'],
                ], 422);
            }

            // Calculate new ProgressCode if ProgressBar or EndDate changed
            $newProgressBar = $request->input('ProgressBar', $task->ProgressBar);
            $originalEndDate = $task->EndDate;

            $newProgressCode = $this->calculateProgressCode(
                $newProgressBar,
                (bool) $request->input('IsCheck', $task->IsCheck),
                $originalEndDate,
                $newEndDate
            );

            if ($request->has('ParentProjectTaskID')) {
                $updateData['ParentProjectTaskID'] = $request->ParentProjectTaskID;
            }
            if ($request->has('SequenceNo')) {
                $updateData['SequenceNo'] = $request->SequenceNo;
            }
            if ($request->has('PriorityCode')) {
                $updateData['PriorityCode'] = $request->PriorityCode;
            }
            if ($request->has('TaskDescription')) {
                $updateData['TaskDescription'] = $request->TaskDescription;
            }
            if ($request->has('StartDate')) {
                $updateData['StartDate'] = $request->StartDate;
            }
            if ($request->has('EndDate')) {
                $updateData['EndDate'] = $request->EndDate;
            }
            if ($request->has('ProgressBar')) {
                $updateData['ProgressBar'] = $newProgressBar;
                $updateData['ProgressCode'] = $newProgressCode; // Auto-update based on logic
            }
            if ($request->has('Note')) {
                $updateData['Note'] = $request->Note;
            }
            if ($request->has('IsCheck')) {
                $updateData['IsCheck'] = $request->IsCheck;
            }

            $task->update($updateData);

            // Delete files if requested
            if ($request->has('delete_files') && !empty($request->delete_files)) {
                ProjectTaskFile::whereIn('ProjectTaskFileID', $request->delete_files)
                    ->where('ProjectTaskID', $taskId)
                    ->update([
                        'IsDelete' => true,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'D',
                    ]);
            }

            // Add new files
            $newTaskFileUrls = [];
            if ($request->has('files') && !empty($request->files)) {
                foreach ($request->files as $fileData) {
                    $fileResult = $this->handleTaskFileUpload(
                        $projectId,
                        $taskId,
                        $fileData,
                        $authUserId,
                        $timestamp
                    );

                    $newTaskFileUrls[] = $fileResult;
                }
            }

            // Update assigned members if provided
            $assignedMembers = [];
            if ($request->has('assignedMembers')) {
                // Remove all existing assignments
                ProjectAssignMember::where('ProjectTaskID', $taskId)->delete();

                // Add new assignments
                if (!empty($request->assignedMembers)) {
                    $members = ProjectMember::where('ProjectID', $projectId)
                        ->whereIn('UserID', $request->assignedMembers)
                        ->where('IsActive', true)
                        ->get()
                        ->keyBy('UserID');

                    foreach ($request->assignedMembers as $assignedUserId) {
                        if (isset($members[$assignedUserId])) {
                            $assignTimestamp = Carbon::now()->timestamp;
                            $assignId = $assignTimestamp . random_numbersu(5);

                            ProjectAssignMember::create([
                                'ProjectAssignMemberID' => $assignId,
                                'AtTimeStamp' => $assignTimestamp,
                                'ByUserID' => $authUserId,
                                'OperationCode' => 'I',
                                'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                                'ProjectTaskID' => $taskId,
                            ]);

                            $assignedMembers[] = [
                                'UserID' => $assignedUserId,
                                'ProjectMemberID' => $members[$assignedUserId]->ProjectMemberID,
                            ];
                        }
                    }
                }
            }

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'ProjectTask',
                'ReferenceRecordID' => $taskId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $task->fresh()->toArray(),
                    'progress_code_changed' => $oldData['ProgressCode'] != $newProgressCode,
                    'new_progress_code' => $newProgressCode,
                    'new_files_count' => count($newTaskFileUrls),
                    'deleted_files_count' => count($request->delete_files ?? []),
                    'assigned_members' => $assignedMembers,
                ]),
                'Note' => $request->Reason ?? 'Project task updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => [
                    'task' => $task->fresh(['files', 'assignments']),
                    'new_files' => $newTaskFileUrls,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detail Project Task 
     */

    public function taskDetail(Request $request, int $project_id, int $task_id)
    {
        $authUserId = $request->auth_user_id;
        $authUser = $request->auth_user;

        // =========================
        // CHECK PROJECT MEMBER
        // =========================
        $projectMember = ProjectMember::where('ProjectID', $project_id)
            ->where('UserID', $authUserId)
            ->where('IsActive', true)
            ->first();

        if (!$projectMember && !$authUser->IsAdministrator) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this project',
            ], 403);
        }

        // =========================
        // GET TASK
        // =========================
        $task = ProjectTask::where('ProjectID', $project_id)
            ->where('ProjectTaskID', $task_id)
            ->where('IsDelete', false)
            ->first();

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        }

        // =========================
        // ACCESS CONTROL
        // =========================
        $isOwnerOrAdmin = $projectMember->IsOwner || $authUser->IsAdministrator;

        $isAssignee = ProjectAssignMember::where('ProjectTaskID', $task_id)
            ->where('ProjectMemberID', $projectMember->ProjectMemberID)
            ->exists();

        if (!$isOwnerOrAdmin && !$isAssignee) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view this task',
            ], 403);
        }

        // =========================
        // LOAD RELATIONS
        // =========================
        $task->load([
            // ASSIGNEE
            'assignments.projectMember.user',

            // TASK FILES (PROGRESS FILE)
            'files' => function ($q) {
                $q->where('IsDelete', false)
                  ->orderBy('AtTimeStamp', 'DESC');
            },

            // FILE UPLOADER
            'files.uploader',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'task'       => $task,
                'assignees'  => $task->assignMembers,
                'files'      => $task->files,
            ],
        ], 200);
    }

    /**
     * Delete Project Task (Soft Delete)
     */
    public function deleteTask(Request $request, $projectId, $taskId)
    {
          $validator = Validator::make($request->all(), [
            'Reason' => 'required|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([   
                    'success' => false,
                    'message' => 'Only project owner can delete tasks',
                ], 403);
            }

            $task = ProjectTask::where('ProjectTaskID', $taskId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found',
                ], 404);
            }

            $oldData = $task->toArray();

            // Soft delete task
            $task->update([
                'IsDelete' => true,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
            ]);

            // Soft delete all task files
            ProjectTaskFile::where('ProjectTaskID', $taskId)
                ->update([
                    'IsDelete' => true,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'D',
                ]);

            // Delete all assignments
            ProjectAssignMember::where('ProjectTaskID', $taskId)->delete();

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'ProjectTask',
                'ReferenceRecordID' => $taskId,
                'Data' => json_encode($oldData),
                'Note' => $request->Reason ?? 'Project task deleted (soft delete)'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add Project Expense
     */
    public function addExpense(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'ExpenseDate' => 'required|date_format:Y-m-d',
            'ExpenseNote' => 'required|string|max:200',
            'CurrencyCode' => 'required|string|max:3',
            'ExpenseAmount' => 'required|numeric',

            // Expense Files (opsional)
            'files' => 'nullable|array',
            'files.*.original_filename' => 'required|string|max:255',
            'files.*.original_content_type' => 'required|string|max:100',
            'files.*.original_file_size' => 'nullable|integer|min:1',
            'files.*.has_converted_pdf' => 'required|boolean',
            'files.*.converted_filename' => 'nullable|required_if:files.*.has_converted_pdf,true|string|max:255',
            'files.*.converted_file_size' => 'nullable|integer|min:1',
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is member
            $isMember = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsActive', true)
                ->exists();

            if (!$isMember && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project members can add expenses',
                ], 403);
            }

            // Create expense
            $expenseId = $timestamp . random_numbersu(5);
            $expense = ProjectExpense::create([
                'ProjectExpenseID' => $expenseId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'ExpenseDate' => $request->ExpenseDate,
                'ExpenseNote' => $request->ExpenseNote,
                'CurrencyCode' => $request->CurrencyCode,
                'ExpenseAmount' => $request->ExpenseAmount,
                'IsDelete' => false,
                'IsCheck' => false,
            ]);

            $expenseFileUrls = [];

            // Generate Presigned URLs for Expense Files (if any)
            if ($request->has('files') && !empty($request->files)) {
                foreach ($request->files as $fileData) {
                    $fileResult = $this->handleExpenseFileUpload(
                        $projectId,
                        $expenseId,
                        $fileData,
                        $authUserId,
                        $timestamp
                    );

                    $expenseFileUrls[] = $fileResult;
                }
            }

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'ProjectExpense',
                'ReferenceRecordID' => $expenseId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'ExpenseNote' => $request->ExpenseNote,
                    'ExpenseAmount' => $request->ExpenseAmount,
                    'CurrencyCode' => $request->CurrencyCode,
                    'TotalFiles' => count($expenseFileUrls),
                ]),
                'Note' => 'Project expense added'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense added successfully',
                'data' => [
                    'expense' => $expense,
                    'files' => $expenseFileUrls,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add expense',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit Project Expense
     */
    public function updateExpense(Request $request, $projectId, $expenseId)
    {
        $validator = Validator::make($request->all(), [
            'ExpenseDate' => 'nullable|date_format:Y-m-d',
            'ExpenseNote' => 'nullable|string|max:200',
            'CurrencyCode' => 'nullable|string|max:3',
            'ExpenseAmount' => 'nullable|numeric',
            'IsCheck' => 'nullable|boolean',

            // New Files to Add
            'files' => 'nullable|array',
            'files.*.original_filename' => 'required|string|max:255',
            'files.*.original_content_type' => 'required|string|max:100',
            'files.*.original_file_size' => 'nullable|integer|min:1',
            'files.*.has_converted_pdf' => 'required|boolean',
            'files.*.converted_filename' => 'nullable|required_if:files.*.has_converted_pdf,true|string|max:255',
            'files.*.converted_file_size' => 'nullable|integer|min:1',

            // Files to Delete
            'delete_files' => 'nullable|array',
            'delete_files.*' => 'integer',
            'Reason' => 'required|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner (for IsCheck) or member (for other fields)
            $member = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsActive', true)
                ->first();

            if (!$member && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project members can edit expenses',
                ], 403);
            }

            // Only owner can update IsCheck
            if ($request->has('IsCheck') && !$member->IsOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can check/uncheck expenses',
                ], 403);
            }

            $expense = ProjectExpense::where('ProjectExpenseID', $expenseId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found',
                ], 404);
            }

            $oldData = $expense->toArray();

            // Update expense
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('ExpenseDate')) {
                $updateData['ExpenseDate'] = $request->ExpenseDate;
            }
            if ($request->has('ExpenseNote')) {
                $updateData['ExpenseNote'] = $request->ExpenseNote;
            }
            if ($request->has('CurrencyCode')) {
                $updateData['CurrencyCode'] = $request->CurrencyCode;
            }
            if ($request->has('ExpenseAmount')) {
                $updateData['ExpenseAmount'] = $request->ExpenseAmount;
            }
            if ($request->has('IsCheck')) {
                $updateData['IsCheck'] = $request->IsCheck;
            }

            $expense->update($updateData);

            // Delete files if requested
            if ($request->has('delete_files') && !empty($request->delete_files)) {
                ProjectExpenseFile::whereIn('ProjectExpenseFileID', $request->delete_files)
                    ->where('ProjectExpenseID', $expenseId)
                    ->update([
                        'IsDelete' => true,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'D',
                    ]);
            }

            // Add new files
            $newExpenseFileUrls = [];
            if ($request->has('files') && !empty($request->input('files'))) {
                foreach ($request->input('files') as $fileData) {
                    $fileResult = $this->handleExpenseFileUpload(
                        $projectId,
                        $expenseId,
                        $fileData,
                        $authUserId,
                        $timestamp
                    );

                    $newExpenseFileUrls[] = $fileResult;
                }
            }

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'ProjectExpense',
                'ReferenceRecordID' => $expenseId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $expense->fresh()->toArray(),
                    'new_files_count' => count($newExpenseFileUrls),
                    'deleted_files_count' => count($request->delete_files ?? []),
                ]),
                'Note' => $request->Reason ?? 'Project expense updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => [
                    'expense' => $expense->fresh(['files']),
                    'new_files' => $newExpenseFileUrls,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Project Expense (Soft Delete)
     */
    public function deleteExpense(Request $request, $projectId, $expenseId)
    {
          $validator = Validator::make($request->all(), [
            'Reason' => 'required|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can delete expenses',
                ], 403);
            }

            $expense = ProjectExpense::where('ProjectExpenseID', $expenseId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found',
                ], 404);
            }

            $oldData = $expense->toArray();

            // Soft delete expense
            $expense->update([
                'IsDelete' => true,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
            ]);

            // Soft delete all expense files
            ProjectExpenseFile::where('ProjectExpenseID', $expenseId)
                ->update([
                    'IsDelete' => true,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'D',
                ]);

            // Update project status
            $this->updateProjectStatus($projectId);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'ProjectExpense',
                'ReferenceRecordID' => $expenseId,
                'Data' => json_encode($oldData),
                'Note' => $request->Reason ?? 'Project expense deleted (soft delete)'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detail Project Expense
     */
    public function expenseDetail(Request $request, int $project_id, int $expense_id)
    {
        $authUser = $request->auth_user;
        $authUserId = $request->auth_user_id;

        // =========================
        // CHECK PROJECT MEMBER
        // =========================
        $projectMember = ProjectMember::where('ProjectID', $project_id)
            ->where('UserID', $authUserId)
            ->where('IsActive', true)
            ->first();

        if (!$projectMember && !$authUser->IsAdministrator) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this project',
            ], 403);
        }

        // =========================
        // GET EXPENSE
        // =========================
        $expense = ProjectExpense::where('ProjectID', $project_id)
            ->where('ProjectExpenseID', $expense_id)
            ->where('IsDelete', false)
            ->first();

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);
        }

        // =========================
        // LOAD FILES + UPLOADER
        // =========================
        $expense->load([
            'files' => function ($q) {
                $q->where('IsDelete', false)
                  ->orderBy('AtTimeStamp', 'DESC');
            },
            'files.uploader',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'expense' => $expense,
                'files'   => $expense->files,
            ],
        ], 200);
    }


    /**
     * Add MiniGoal
     */
    public function addMiniGoal(Request $request, $projectId)
    {
        $validator = Validator::make($request->all(), [
            'SequenceNo' => 'nullable|integer',
            'MiniGoalDescription' => 'required|string|max:200',
            'MiniGoalCategoryCode' => 'required|in:1,2,3', // 1=$, 2=%, 3=#
            'MiniGoalFirstPrefixCode' => 'nullable|string|max:10',
            'MiniGoalLastPrefixCode' => 'nullable|string|max:10',
            'TargetValue' => 'required|integer|min:0',
            'ActualValue' => 'nullable|integer|min:0',
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $ownerCheck = $this->checkSingleOwner($projectId, $authUserId);
            if (!$ownerCheck['is_owner'] && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => $ownerCheck['message'],
                ], 403);
            }

            $miniGoalId = $timestamp . random_numbersu(5);
            $miniGoal = MiniGoal::create([
                'MiniGoalID' => $miniGoalId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ProjectID' => $projectId,
                'SequenceNo' => $request->SequenceNo,
                'MiniGoalDescription' => $request->MiniGoalDescription,
                'MiniGoalCategoryCode' => $request->MiniGoalCategoryCode,
                'MiniGoalFirstPrefixCode' => $request->MiniGoalFirstPrefixCode ?? "",
                'MiniGoalLastPrefixCode' => $request->MiniGoalLastPrefixCode ?? "",
                'TargetValue' => $request->TargetValue,
                'ActualValue' => $request->input('ActualValue', 0),
                'IsDelete' => false,
            ]);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'MiniGoal',
                'ReferenceRecordID' => $miniGoalId,
                'Data' => json_encode([
                    'ProjectID' => $projectId,
                    'MiniGoalDescription' => $request->MiniGoalDescription,
                    'MiniGoalCategoryCode' => $request->MiniGoalCategoryCode,
                    'MiniGoalFirstPrefixCode' => $request->MiniGoalFirstPrefixCode ?? "",
                    'MiniGoalLastPrefixCode' => $request->MiniGoalLastPrefixCode ?? "", 
                    'TargetValue' => $request->TargetValue,
                    'ActualValue' => $request->input('ActualValue', 0),
                ]),
                'Note' => 'Mini goal added'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mini goal added successfully',
                'data' => $miniGoal,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add mini goal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update MiniGoal
     */
    public function updateMiniGoal(Request $request, $projectId, $miniGoalId)
    {
        $validator = Validator::make($request->all(), [
            'SequenceNo' => 'nullable|integer',
            'MiniGoalDescription' => 'nullable|string|max:200',
            'MiniGoalCategoryCode' => 'nullable|in:1,2,3',
            'MiniGoalFirstPrefixCode' => 'nullable|string|max:10',
            'MiniGoalLastPrefixCode' => 'nullable|string|max:10',
            'TargetValue' => 'nullable|integer|min:0',
            'ActualValue' => 'nullable|integer|min:0',
            'Reason' => 'nullable|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }

            // Check if user is owner
            $ownerCheck = $this->checkSingleOwner($projectId, $authUserId);
            if (!$ownerCheck['is_owner'] && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => $ownerCheck['message'],
                ], 403);
            }

            $miniGoal = MiniGoal::where('MiniGoalID', $miniGoalId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$miniGoal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mini goal not found',
                ], 404);
            }

            $oldData = $miniGoal->toArray();

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];
            if ($request->has('SequenceNo')) {
                $updateData['SequenceNo'] = $request->SequenceNo;
            }
            if ($request->has('MiniGoalDescription')) {
                $updateData['MiniGoalDescription'] = $request->MiniGoalDescription;
            }
            if ($request->has('MiniGoalCategoryCode')) {
                $updateData['MiniGoalCategoryCode'] = $request->MiniGoalCategoryCode;
            }
            if ($request->has('MiniGoalFirstPrefixCode')) {
                $updateData['MiniGoalFirstPrefixCode'] = $request->MiniGoalFirstPrefixCode;
            }
            if ($request->has('MiniGoalLastPrefixCode')) {
                $updateData['MiniGoalLastPrefixCode'] = $request->MiniGoalLastPrefixCode;
            }
            if ($request->has('TargetValue')) {
                $updateData['TargetValue'] = $request->TargetValue;
            }
            if ($request->has('ActualValue')) {
                $updateData['ActualValue'] = $request->ActualValue;
            }

            $miniGoal->update($updateData);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'MiniGoal',
                'ReferenceRecordID' => $miniGoalId,
                'Data' => json_encode([
                    'old' => $oldData,
                    'new' => $miniGoal->fresh()->toArray(),
                ]),
                'Note' => $request->Reason ?? 'Mini goal updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mini goal updated successfully',
                'data' => $miniGoal->fresh(),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update mini goal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete MiniGoal (Soft Delete)
     */
    public function deleteMiniGoal(Request $request, $projectId, $miniGoalId)
    {
          $validator = Validator::make($request->all(), [
            'Reason' => 'required|string|max:200'
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
            $user = $request->auth_user;

            if ($this->isProjectVoid($projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project with status 00 (VOID) cannot be updated',
                ], 409);
            }
            // Check if user is owner
            $ownerCheck = $this->checkSingleOwner($projectId, $authUserId);
            if (!$ownerCheck['is_owner'] && !$user->IsAdministrator) {
                return response()->json([
                    'success' => false,
                    'message' => $ownerCheck['message'],
                ], 403);
            }

            $miniGoal = MiniGoal::where('MiniGoalID', $miniGoalId)
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$miniGoal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mini goal not found',
                ], 404);
            }

            $oldData = $miniGoal->toArray();

            $miniGoal->update([
                'IsDelete' => true,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
            ]);

            // Create Audit Log
            AuditLog::create([
                'AuditLogID' => $timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'MiniGoal',
                'ReferenceRecordID' => $miniGoalId,
                'Data' => json_encode($oldData),
                'Note' => $request->Reason ?? 'Mini goal deleted (soft delete)'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mini goal deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete mini goal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List ProjectTask By Project (Global - Admin / Owner / Assignee)
     */
    public function projectTasks(Request $request, int $projectId)
    {
        $validator = Validator::make($request->all(), [
            'UserID'      => 'nullable|array',
            'UserID.*'    => 'integer|exists:User,UserID',
            'StartDate'   => 'nullable|date_format:Y-m-d',
            'EndDate'     => 'nullable|date_format:Y-m-d',
            'IsCheck'     => 'nullable|boolean',
            'per_page'    => 'nullable|integer|min:1|max:100',
            'page'        => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $authUser   = $request->auth_user;
        $authUserId = $request->auth_user_id;
        $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
        $perPage    = $request->per_page ?? 10;
        $page       = $request->page ?? 1;

        // =========================
        // PROJECT VALIDATION
        // =========================
        $project = Project::where('ProjectID', $projectId)
            ->where('IsDelete', false)
            ->first();

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found',
            ], 404);
        }

        // =========================
        // NON ADMIN → MUST BE MEMBER
        // =========================
        if (!$isAdmin) {
            $isMember = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsActive', true)
                ->exists();

            if (!$isMember) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not a member of this project',
                ], 403);
            }
        }

        // =========================
        // BASE QUERY
        // =========================
        $query = ProjectTask::query()
            ->select([
                'ProjectTask.*',
                'Project.ProjectName',
            ])
            ->join('Project', 'Project.ProjectID', '=', 'ProjectTask.ProjectID')
            ->where('ProjectTask.ProjectID', $projectId)
            ->where('ProjectTask.IsDelete', false);

        // =========================
        // USER FILTER (ADMIN / OWNER ONLY)
        // =========================
        if ($request->filled('UserID')) {

            if (!$isAdmin) {
                $isOwner = ProjectMember::where('ProjectID', $projectId)
                    ->where('UserID', $authUserId)
                    ->where('IsOwner', true)
                    ->where('IsActive', true)
                    ->exists();

                if (!$isOwner) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only admin or project owner can filter by user',
                    ], 403);
                }
            }

            $query->where(function ($q) use ($request) {

                // creator / owner task
                $q->whereIn('ProjectTask.CreatedBy', $request->UserID)

                // assignee
                ->orWhereExists(function ($sub) use ($request) {
                    $sub->selectRaw(1)
                        ->from('ProjectAssignMember as pam')
                        ->join('ProjectMember as pm', 'pm.ProjectMemberID', '=', 'pam.ProjectMemberID')
                        ->whereColumn('pam.ProjectTaskID', 'ProjectTask.ProjectTaskID')
                        ->whereIn('pm.UserID', $request->UserID)
                        ->where('pm.IsActive', true);
                });
            });
        }

        // =========================
        // DATE FILTER (OVERLAP)
        // =========================
        if ($request->filled('StartDate') || $request->filled('EndDate')) {

            if ($request->filled('StartDate')) {
                $query->whereDate('ProjectTask.EndDate', '>=', $request->StartDate);
            }

            if ($request->filled('EndDate')) {
                $query->whereDate('ProjectTask.StartDate', '<=', $request->EndDate);
            }
        }

        // =========================
        // IS CHECK FILTER
        // =========================
        if ($request->has('IsCheck')) {
            $query->where('ProjectTask.IsCheck', $request->IsCheck);
        }

        // =========================
        // PAGINATION
        // =========================
        $tasks = $query
            ->orderBy('ProjectTask.StartDate', 'ASC')
            ->orderBy('ProjectTask.AtTimeStamp', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'project' => [
                'ProjectID'   => $project->ProjectID,
                'ProjectName' => $project->ProjectName,
            ],
            'data' => $tasks,
        ]);
    }

    /**
     * List MY ProjectTask (Global - Admin / Non-Admin)
     */
    public function byTasks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ProjectID'   => 'nullable|array',
            'ProjectID.*' => 'integer',
            'UserID'      => 'nullable|array',
            'UserID.*'    => 'integer|exists:User,UserID',
            'StartDate'   => 'nullable|date_format:Y-m-d',
            'EndDate'     => 'nullable|date_format:Y-m-d',
            'IsCheck'     => 'nullable|boolean',
            'mode'        => 'nullable|in:OWNER,NON_OWNER,ALL',
            'per_page'    => 'nullable|integer|min:1|max:100',
            'page'        => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $authUser   = $request->auth_user;
        $authUserId = $request->auth_user_id;
        $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
        $perPage    = $request->per_page ?? 10;
        $page       = $request->page ?? 1;
        $mode       = $request->mode ?? 'ALL';

        // =========================
        // TARGET USER RESOLUTION
        // =========================
        if ($isAdmin && $request->filled('UserID')) {
            $targetUserIds = $request->UserID;
        } else {
            $targetUserIds = [$authUserId];
        }

        // =========================
        // BASE QUERY
        // =========================
        $query = ProjectTask::query()
            ->select([
                'ProjectTask.*',
                'Project.ProjectName',
                DB::raw("
                    CASE
                        WHEN ProjectTask.OperationCode = 'I' AND ProjectTask.ByUserID IS NOT NULL
                            THEN ProjectTask.ByUserID
                        ELSE (
                            SELECT al.ByUserID
                            FROM AuditLog al
                            WHERE al.ReferenceTable = 'ProjectTask'
                                AND al.ReferenceRecordID = ProjectTask.ProjectTaskID
                                AND al.OperationCode = 'I'
                            ORDER BY al.AtTimeStamp ASC
                            LIMIT 1
                        )
                    END AS CreatedBy
                "),
            ])
            ->join('Project', 'Project.ProjectID', '=', 'ProjectTask.ProjectID')
            ->where('ProjectTask.IsDelete', false)
            ->where('Project.IsDelete', false);

        // =========================
        // PROJECT FILTER
        // =========================
        if ($request->filled('ProjectID')) {
            $query->whereIn('ProjectTask.ProjectID', $request->ProjectID);
        }

        // =========================
        // MODE FILTER
        // =========================
        $query->where(function ($q) use ($mode, $targetUserIds) {
            $ownerFilter = function ($ownerQuery) use ($targetUserIds) {
                $ownerQuery->where(function ($directCreatorQuery) use ($targetUserIds) {
                    $directCreatorQuery->where('ProjectTask.OperationCode', 'I')
                        ->whereNotNull('ProjectTask.ByUserID')
                        ->whereIn('ProjectTask.ByUserID', $targetUserIds);
                })->orWhere(function ($auditCreatorQuery) use ($targetUserIds) {
                    $auditCreatorQuery->where('ProjectTask.OperationCode', 'U')
                        ->whereExists(function ($sub) use ($targetUserIds) {
                            $sub->selectRaw(1)
                                ->from('AuditLog as al')
                                ->where('al.ReferenceTable', 'ProjectTask')
                                ->whereColumn('al.ReferenceRecordID', 'ProjectTask.ProjectTaskID')
                                ->where('al.OperationCode', 'I')
                                ->whereIn('al.ByUserID', $targetUserIds);
                        });
                });
            };

            if ($mode === 'OWNER' || $mode === 'ALL') {
                $q->where($ownerFilter);
            }

            if ($mode === 'NON_OWNER') {
                $q->whereExists(function ($sub) use ($targetUserIds) {
                    $sub->selectRaw(1)
                        ->from('ProjectAssignMember as pam')
                        ->join('ProjectMember as pm', 'pm.ProjectMemberID', '=', 'pam.ProjectMemberID')
                        ->whereColumn('pam.ProjectTaskID', 'ProjectTask.ProjectTaskID')
                        ->whereIn('pm.UserID', $targetUserIds)
                        ->where('pm.IsActive', true);
                });
            }

            if ($mode === 'ALL') {
                $q->orWhereExists(function ($sub) use ($targetUserIds) {
                    $sub->selectRaw(1)
                        ->from('ProjectAssignMember as pam')
                        ->join('ProjectMember as pm', 'pm.ProjectMemberID', '=', 'pam.ProjectMemberID')
                        ->whereColumn('pam.ProjectTaskID', 'ProjectTask.ProjectTaskID')
                        ->whereIn('pm.UserID', $targetUserIds)
                        ->where('pm.IsActive', true);
                });
            }
        });

        // =========================
        // DATE FILTER (OVERLAP)
        // =========================
        if ($request->filled('StartDate') || $request->filled('EndDate')) {

            if ($request->filled('StartDate')) {
                $query->whereDate('ProjectTask.EndDate', '>=', $request->StartDate);
            }

            if ($request->filled('EndDate')) {
                $query->whereDate('ProjectTask.StartDate', '<=', $request->EndDate);
            }
        }

        // =========================
        // IS CHECK
        // =========================
        if ($request->has('IsCheck')) {
            $query->where('ProjectTask.IsCheck', $request->IsCheck);
        }

        // =========================
        // PAGINATION
        // =========================
        $tasks = $query
            ->orderBy('ProjectTask.StartDate', 'ASC')
            ->orderBy('ProjectTask.AtTimeStamp', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'mode'    => $mode,
            'data'    => $tasks,
        ]);
    }

    public function listAllTask(Request $request)
    {
        $validator = Validator::make($request->all(), [
        'mode'       => 'required_unless:is_admin,true|in:OWNER,NON_OWNER,ALL',
        'ProjectID'  => 'nullable|array',
        'ProjectID.*'=> 'integer',
        'UserID'     => 'nullable|integer|exists:User,UserID',
        'SearchDate' => 'nullable|date_format:Y-m-d',
        'StartDate'  => 'nullable|date_format:Y-m-d',
        'EndDate'    => 'nullable|date_format:Y-m-d',
        'IsCheck'    => 'nullable|boolean',
        'per_page'   => 'nullable|integer|min:1|max:100',
        'page'        => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $authUser   = $request->auth_user;
        $authUserId = $request->auth_user_id;
        $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
        $mode       = $request->mode;
        $perPage    = $request->per_page ?? 10;
        $page       = $request->page ?? 1;

        // =========================
        // BASE QUERY (PROJECT TASK)
        // =========================
        $query = ProjectTask::query()
            ->select([
                'ProjectTask.*',
                'Project.ProjectName',
            ])
            ->join(
                'Project',
                'Project.ProjectID',
                '=',
                'ProjectTask.ProjectID'
            )
            ->where('ProjectTask.IsDelete', false)
            ->where('Project.IsDelete', false);

        // =========================
        // PROJECT FILTER
        // =========================
        if ($request->filled('ProjectID')) {
            $query->whereIn('ProjectTask.ProjectID', $request->ProjectID);
        }

        // =========================
        // ROLE FILTER
        // =========================
        if ($isAdmin) {
            // ADMIN → ALL TASK
            if ($request->filled('UserID')) {
                // Filter by user involvement (owner OR assignee)
                $query->where(function ($q) use ($request) {

                    // Owner side
                    $q->whereExists(function ($sub) use ($request) {
                        $sub->selectRaw(1)
                            ->from('ProjectMember as pm')
                            ->whereColumn('pm.ProjectID', 'ProjectTask.ProjectID')
                            ->where('pm.UserID', $request->UserID)
                            ->where('pm.IsActive', true);
                    })

                    // Assignee side
                    ->orWhereExists(function ($sub) use ($request) {
                        $sub->selectRaw(1)
                            ->from('ProjectAssignMember as pam')
                            ->join(
                                'ProjectMember as pm2',
                                'pm2.ProjectMemberID',
                                '=',
                                'pam.ProjectMemberID'
                            )
                            ->whereColumn(
                                'pam.ProjectTaskID',
                                'ProjectTask.ProjectTaskID'
                            )
                            ->where('pm2.UserID', $request->UserID)
                            ->where('pm2.IsActive', true);
                    });
                });
            } else {
                $query->whereRaw('1 = 0');
            }

        } else {
            // NON ADMIN
            if(!$isAdmin) {
                // 🔹 USER HARUS MEMBER PROJECT
                $query->whereExists(function ($sub) use ($authUserId) {
                    $sub->selectRaw(1)
                        ->from('ProjectMember as pm')
                        ->whereColumn('pm.ProjectID', 'ProjectTask.ProjectID')
                        ->where('pm.UserID', $authUserId)
                        ->where('pm.IsActive', true);
                });

                    if (!$request->filled('ProjectID')) {
                        if ($mode === 'OWNER') {
                        $query->whereExists(function ($sub) use ($authUserId) {
                            $sub->selectRaw(1)
                                ->from('ProjectMember')
                                ->whereColumn(
                                    'ProjectMember.ProjectID',
                                    'ProjectTask.ProjectID'
                                )
                                ->where('ProjectMember.UserID', $authUserId)
                                ->where('ProjectMember.IsOwner', true)
                                ->where('ProjectMember.IsActive', true);
                        });

                    } else if($mode === "NON_OWNER") {
                        $query->whereExists(function ($sub) use ($authUserId) {
                            $sub->selectRaw(1)
                                ->from('ProjectAssignMember as pam')
                                ->join(
                                    'ProjectMember as pm',
                                    'pm.ProjectMemberID',
                                    '=',
                                    'pam.ProjectMemberID'
                                )
                                ->whereColumn(
                                    'pam.ProjectTaskID',
                                    'ProjectTask.ProjectTaskID'
                                )
                                ->where('pm.UserID', $authUserId)
                                ->where('pm.IsActive', true);
                        });
                    } else {
                         // 🔥 ALL (OWNER OR ASSIGNEE)
                        $query->where(function ($q) use ($authUserId) {

                            // OWNER SIDE
                            $q->whereExists(function ($sub) use ($authUserId) {
                                $sub->selectRaw(1)
                                    ->from('ProjectMember')
                                    ->whereColumn(
                                        'ProjectMember.ProjectID',
                                        'ProjectTask.ProjectID'
                                    )
                                    ->where('ProjectMember.UserID', $authUserId)
                                    ->where('ProjectMember.IsActive', true);
                            })

                            // ASSIGNEE SIDE
                            ->orWhereExists(function ($sub) use ($authUserId) {
                                $sub->selectRaw(1)
                                    ->from('ProjectAssignMember as pam')
                                    ->join(
                                        'ProjectMember as pm',
                                        'pm.ProjectMemberID',
                                        '=',
                                        'pam.ProjectMemberID'
                                    )
                                    ->whereColumn(
                                        'pam.ProjectTaskID',
                                        'ProjectTask.ProjectTaskID'
                                    )
                                    ->where('pm.UserID', $authUserId)
                                    ->where('pm.IsActive', true);
                            });
                        });
                    }
                }
            }
        }

        // =========================
        // DATE FILTER (OVERLAP LOGIC)
        // =========================
        $today = Carbon::today()->format('Y-m-d');

        if ($request->filled('SearchDate')) {

            $query->whereDate('ProjectTask.StartDate', '<=', $request->SearchDate)
                  ->whereDate('ProjectTask.EndDate', '>=', $request->SearchDate);

        } elseif ($request->filled('StartDate') || $request->filled('EndDate')) {

            if ($request->filled('StartDate')) {
                $query->whereDate('ProjectTask.EndDate', '>=', $request->StartDate);
            }

            if ($request->filled('EndDate')) {
                $query->whereDate('ProjectTask.StartDate', '<=', $request->EndDate);
            }

        } else {

            $query->whereDate('ProjectTask.StartDate', '<=', $today)
                  ->whereDate('ProjectTask.EndDate', '>=', $today);
        }

        // =========================
        // IsCheck FILTER
        // =========================
        if ($request->has('IsCheck')) {
            $query->where('ProjectTask.IsCheck', $request->IsCheck);
        }

        // =========================
        // PAGINATION
        // =========================
        $files = $query
            ->orderBy('ProjectTask.StartDate', 'ASC')
            ->orderBy('ProjectTask.AtTimeStamp', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'role' => $isAdmin ? 'ADMIN' : $mode,
            'data' => $files,
        ], 200);
    }

    /**
     * List ALL ProjectExpense (Global - Admin / Owner)
     */
    public function listAllExpense(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mode'        => 'required_unless:is_admin,true|in:OWNER,NON_OWNER,ALL',
            'ProjectID'   => 'nullable|array',
            'ProjectID.*' => 'integer',
            'UserID'      => 'nullable|integer|exists:User,UserID',
            'SearchDate'  => 'nullable|date_format:Y-m-d',
            'StartDate'   => 'nullable|date_format:Y-m-d',
            'EndDate'     => 'nullable|date_format:Y-m-d',
            'per_page'    => 'nullable|integer|min:1|max:100',
            'page'        => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $authUser   = $request->auth_user;
        $authUserId = $request->auth_user_id;
        $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
        $mode       = $request->mode;
        $perPage    = $request->per_page ?? 10;
        $page       = $request->page ?? 1;

        // =========================
        // BASE QUERY (PROJECT EXPENSE)
        // =========================
        $query = ProjectExpense::query()
            ->select([
                'ProjectExpense.*',
                'Project.ProjectName',
            ])
            ->join(
                'Project',
                'Project.ProjectID',
                '=',
                'ProjectExpense.ProjectID'
            )
            ->where('ProjectExpense.IsDelete', false)
            ->where('Project.IsDelete', false);

        // =========================
        // PROJECT FILTER
        // =========================
        if ($request->filled('ProjectID')) {
            $query->whereIn('ProjectExpense.ProjectID', $request->ProjectID);
        }

        // =========================
        // ROLE FILTER
        // =========================
        if ($isAdmin) {

            // ADMIN → wajib UserID
            if ($request->filled('UserID')) {

                $userId = $request->UserID;

                $query->whereExists(function ($sub) use ($userId) {
                    $sub->selectRaw(1)
                        ->from('ProjectMember as pm')
                        ->whereColumn('pm.ProjectID', 'ProjectExpense.ProjectID')
                        ->where('pm.UserID', $userId)
                        ->where('pm.IsActive', true);
                });

            } else {
                // Admin tanpa UserID → kosong
                $query->whereRaw('1 = 0');
            }

        } else {
            // NON ADMIN
            if ($mode === 'OWNER') {

                $query->whereExists(function ($sub) use ($authUserId) {
                    $sub->selectRaw(1)
                        ->from('ProjectMember')
                        ->whereColumn(
                            'ProjectMember.ProjectID',
                            'ProjectExpense.ProjectID'
                        )
                        ->where('ProjectMember.UserID', $authUserId)
                        ->where('ProjectMember.IsOwner', true)
                        ->where('ProjectMember.IsActive', true);
                });

            } else {

                // NON OWNER → expense yang dibuat user tsb
                $query->where('ProjectExpense.ByUserID', $authUserId);
            }
        }

        // =========================
        // DATE FILTER (OVERLAP LOGIC)
        // =========================
        $today = Carbon::today()->format('Y-m-d');

        if ($request->filled('SearchDate')) {

            $query->whereDate('ProjectExpense.ExpenseDate', $request->SearchDate);

        } elseif ($request->filled('StartDate') || $request->filled('EndDate')) {

            if ($request->filled('StartDate')) {
                $query->whereDate('ProjectExpense.ExpenseDate', '>=', $request->StartDate);
            }

            if ($request->filled('EndDate')) {
                $query->whereDate('ProjectExpense.ExpenseDate', '<=', $request->EndDate);
            }

        } else {

            $query->whereDate('ProjectExpense.ExpenseDate', $today);
        }

        // =========================
        // PAGINATION
        // =========================
        $expenses = $query
            ->orderBy('ProjectExpense.ExpenseDate', 'DESC')
            ->orderBy('ProjectExpense.AtTimeStamp', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'role'    => $isAdmin ? 'ADMIN' : $mode,
            'data'    => $expenses,
        ], 200);
    }

    /**
     * Get all project files grouped by category.
     *
     * GET /projects/{id}/files
     */
    public function projectFiles(Request $request, $projectId)
    {
        try {
            $authUser   = $request->auth_user;
            $authUserId = $request->auth_user_id;
            $isAdmin    = (bool) ($authUser->IsAdministrator ?? false);
    
            // =========================
            // ACCESS CHECK
            // =========================
            if (!$isAdmin) {
                $isMember = ProjectMember::where('ProjectID', $projectId)
                    ->where('UserID', $authUserId)
                    ->where('IsActive', true)
                    ->exists();
    
                if (!$isMember) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this project',
                    ], 403);
                }
            }
    
            $type = strtolower((string) $request->query('type', 'all')); // task | expense | project | all
            $today = Carbon::today()->format('Y-m-d');
            $globalPerPage = max(1, min(100, (int) $request->query('per_page', 10)));
            $globalPage = max(1, (int) $request->query('page', 1));
            $taskPerPage = max(1, min(100, (int) $request->query('task_per_page', $globalPerPage)));
            $expensePerPage = max(1, min(100, (int) $request->query('expense_per_page', $globalPerPage)));
            $projectPerPage = max(1, min(100, (int) $request->query('project_per_page', $globalPerPage)));
            $taskPage = max(1, (int) $request->query('task_page', $globalPage));
            $expensePage = max(1, (int) $request->query('expense_page', $globalPage));
            $projectPage = max(1, (int) $request->query('project_page', $globalPage));
            $projectHeader = Project::query()
                ->select(['ProjectID', 'StartDate', 'EndDate'])
                ->where('ProjectID', $projectId)
                ->where('IsDelete', false)
                ->first();

            if (!$projectHeader) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }
    
            // =========================
            // TASK FILES QUERY
            // =========================
            $taskFiles = [
                'items' => [],
                'pagination' => [
                    'current_page' => $taskPage,
                    'per_page' => $taskPerPage,
                    'total' => 0,
                    'last_page' => 0,
                ],
            ];

            if (in_array($type, ['task', 'all'], true)) {
                $taskFilesQuery = ProjectTaskFile::query()
                    ->select([
                        'ProjectTaskFile.ProjectTaskFileID as FileID',
                        'ProjectTaskFile.ProjectTaskID as ReferenceID',
                        'ProjectTaskFile.OriginalFileName as original_filename',
                        'ProjectTaskFile.DocumentUrl as document_url',
                        'ProjectTaskFile.DocumentOriginalUrl as document_original_url',
                        'ProjectTaskFile.AtTimeStamp as at_timestamp',
                        DB::raw("'task' as source_type"),
                    ])
                    ->join(
                        'ProjectTask',
                        'ProjectTask.ProjectTaskID',
                        '=',
                        'ProjectTaskFile.ProjectTaskID'
                    )
                    ->where('ProjectTask.ProjectID', $projectId)
                    ->where('ProjectTask.IsDelete', false)
                    ->where('ProjectTaskFile.IsDelete', false);
    
                // IsCheck filter (TASK ONLY)
                if ($request->filled('is_check')) {
                    $taskFilesQuery->where(
                        'ProjectTask.IsCheck',
                        filter_var($request->is_check, FILTER_VALIDATE_BOOLEAN)
                    );
                }
    
                // DATE FILTER (overlap + default today)
                $this->applyDateFilter(
                    $taskFilesQuery,
                    'ProjectTask.StartDate',
                    'ProjectTask.EndDate',
                    $request,
                    $today,
                    optional($projectHeader->StartDate)->format('Y-m-d'),
                    optional($projectHeader->EndDate)->format('Y-m-d')
                );

                $taskFilesPaginator = $taskFilesQuery
                    ->orderBy('ProjectTaskFile.AtTimeStamp', 'DESC')
                    ->paginate($taskPerPage, ['*'], 'task_page', $taskPage);

                $taskFiles = [
                    'items' => $taskFilesPaginator->items(),
                    'pagination' => [
                        'current_page' => $taskFilesPaginator->currentPage(),
                        'per_page' => $taskFilesPaginator->perPage(),
                        'total' => $taskFilesPaginator->total(),
                        'last_page' => $taskFilesPaginator->lastPage(),
                    ],
                ];
            }
    
            // =========================
            // EXPENSE FILES QUERY
            // =========================
            $expenseFiles = [
                'items' => [],
                'pagination' => [
                    'current_page' => $expensePage,
                    'per_page' => $expensePerPage,
                    'total' => 0,
                    'last_page' => 0,
                ],
            ];

            if (in_array($type, ['expense', 'all'], true)) {
                $expenseFilesQuery = ProjectExpenseFile::query()
                    ->select([
                        'ProjectExpenseFile.ProjectExpenseFileID as FileID',
                        'ProjectExpenseFile.ProjectExpenseID as ReferenceID',
                        'ProjectExpenseFile.OriginalFileName as original_filename',
                        'ProjectExpenseFile.DocumentUrl as document_url',
                        'ProjectExpenseFile.DocumentOriginalUrl as document_original_url',
                        'ProjectExpenseFile.AtTimeStamp as at_timestamp',
                        DB::raw("'expense' as source_type"),
                    ])
                    ->join(
                        'ProjectExpense',
                        'ProjectExpense.ProjectExpenseID',
                        '=',
                        'ProjectExpenseFile.ProjectExpenseID'
                    )
                    ->where('ProjectExpense.ProjectID', $projectId)
                    ->where('ProjectExpense.IsDelete', false)
                    ->where('ProjectExpenseFile.IsDelete', false);
    
                $this->applyDateFilter(
                    $expenseFilesQuery,
                    'ProjectExpense.ExpenseDate',
                    'ProjectExpense.ExpenseDate',
                    $request,
                    $today,
                    optional($projectHeader->StartDate)->format('Y-m-d'),
                    optional($projectHeader->EndDate)->format('Y-m-d')
                );

                $expenseFilesPaginator = $expenseFilesQuery
                    ->orderBy('ProjectExpenseFile.AtTimeStamp', 'DESC')
                    ->paginate($expensePerPage, ['*'], 'expense_page', $expensePage);

                $expenseFiles = [
                    'items' => $expenseFilesPaginator->items(),
                    'pagination' => [
                        'current_page' => $expenseFilesPaginator->currentPage(),
                        'per_page' => $expenseFilesPaginator->perPage(),
                        'total' => $expenseFilesPaginator->total(),
                        'last_page' => $expenseFilesPaginator->lastPage(),
                    ],
                ];
            }

            // =========================
            // PROJECT FILES QUERY
            // =========================
            $projectFiles = [
                'items' => [],
                'pagination' => [
                    'current_page' => $projectPage,
                    'per_page' => $projectPerPage,
                    'total' => 0,
                    'last_page' => 0,
                ],
            ];

            if (in_array($type, ['project', 'all'], true)) {
                $projectFilesQuery = Project::query()
                    ->select([
                        'ProjectID as FileID',
                        'ProjectID as ReferenceID',
                        'ProjectName as original_filename',
                        'DocumentUrl as document_url',
                        'DocumentOriginalUrl as document_original_url',
                        'AtTimeStamp as at_timestamp',
                        DB::raw("'project' as source_type"),
                    ])
                    ->where('ProjectID', $projectId)
                    ->where('IsDelete', false)
                    ->whereNotNull('DocumentPath');

                $this->applyDateFilter(
                    $projectFilesQuery,
                    'StartDate',
                    'EndDate',
                    $request,
                    $today,
                    optional($projectHeader->StartDate)->format('Y-m-d'),
                    optional($projectHeader->EndDate)->format('Y-m-d')
                );

                $projectFilesPaginator = $projectFilesQuery
                    ->orderBy('AtTimeStamp', 'DESC')
                    ->paginate($projectPerPage, ['*'], 'project_page', $projectPage);

                $projectFiles = [
                    'items' => $projectFilesPaginator->items(),
                    'pagination' => [
                        'current_page' => $projectFilesPaginator->currentPage(),
                        'per_page' => $projectFilesPaginator->perPage(),
                        'total' => $projectFilesPaginator->total(),
                        'last_page' => $projectFilesPaginator->lastPage(),
                    ],
                ];
            }

            $totalFiles = $taskFiles['pagination']['total']
                + $expenseFiles['pagination']['total']
                + $projectFiles['pagination']['total'];

            return response()->json([
                'success' => true,
                'data' => [
                    'project_id' => $projectId,
                    'total_files' => $totalFiles,
                    'categories' => [
                        'task' => $taskFiles,
                        'expense' => $expenseFiles,
                        'project' => $projectFiles,
                    ],
                ],
            ], 200);
    
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch project files',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function sendProjectCreatedNotifications(Project $project, array $memberIds): void
    {
        $emails = $this->resolveEmailsFromUserOrEmployeeIds($memberIds);
        if (empty($emails)) {
            return;
        }

        $subject = 'Project Baru Dibuat';
        $body = "Project \"{$project->ProjectName}\" sudah dibuat dan Anda terdaftar sebagai member.";
        $this->sendSimpleEmail($emails, $subject, $body);
    }

    private function sendAssigneeNotification(
        ?Project $project,
        $taskId,
        string $taskDescription,
        array $assigneeIds,
        string $type = 'assigned'
    ): void {
        if (!$project) {
            return;
        }

        $emails = $this->resolveEmailsFromUserOrEmployeeIds($assigneeIds);
        if (empty($emails)) {
            return;
        }

        if ($type === 'rejected') {
            $subject = 'Task Ditolak Owner';
            $body = "Task #{$taskId} ({$taskDescription}) pada project \"{$project->ProjectName}\" ditolak owner. Silakan update progress kembali.";
        } else {
            $subject = 'Anda Mendapat Task Baru';
            $body = "Anda ditugaskan pada task #{$taskId} ({$taskDescription}) di project \"{$project->ProjectName}\".";
        }

        $this->sendSimpleEmail($emails, $subject, $body);
    }

    private function sendOwnerApprovalNotification(?Project $project, $taskId, string $taskDescription): void
    {
        if (!$project) {
            return;
        }

        $ownerUserId = ProjectMember::where('ProjectID', $project->ProjectID)
            ->where('IsOwner', true)
            ->where('IsActive', true)
            ->value('UserID');

        if (!$ownerUserId) {
            return;
        }

        $emails = $this->resolveEmailsFromUserOrEmployeeIds([(int) $ownerUserId]);
        if (empty($emails)) {
            return;
        }

        $subject = 'Approval Task Diperlukan';
        $body = "Task #{$taskId} ({$taskDescription}) pada project \"{$project->ProjectName}\" sudah mencapai 100% dan menunggu pengecekan Anda.";
        $this->sendSimpleEmail($emails, $subject, $body);
    }

    private function resolveEmailsFromUserOrEmployeeIds(array $ids): array
    {
        $normalizedIds = array_values(array_unique(array_map(static fn($id) => (int) $id, $ids)));
        if (empty($normalizedIds)) {
            return [];
        }

        $emails = User::whereIn('UserID', $normalizedIds)
            ->pluck('Email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $foundUserIds = User::whereIn('UserID', $normalizedIds)->pluck('UserID')->all();
        $missingIds = array_values(array_diff($normalizedIds, array_map('intval', $foundUserIds)));

        if (!empty($missingIds)) {
            $employeeUserIds = Employee::whereIn('EmployeeID', $missingIds)
                ->pluck('EmployeeID')
                ->map(static fn($id) => (int) $id)
                ->all();

            if (!empty($employeeUserIds)) {
                $employeeEmails = User::whereIn('UserID', $employeeUserIds)
                    ->pluck('Email')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $emails = array_values(array_unique(array_merge($emails, $employeeEmails)));
            }
        }

        return $emails;
    }

    private function sendSimpleEmail(array $emails, string $subject, string $body): void
    {
        foreach ($emails as $email) {
            try {
                Mail::raw($body, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::warning('Failed to send project email notification', [
                    'email' => $email,
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

}
