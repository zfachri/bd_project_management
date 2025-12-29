<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectExpense;
use App\Models\ProjectExpenseFile;
use App\Models\ProjectAssignMember;
use App\Services\MinioService;
use App\Models\AuditLog;

class ProjectController extends Controller
{
    protected $minioService;

    public function __construct(MinioService $minioService)
    {
        $this->minioService = $minioService;
    }

    public function store(Request $request)
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
            'project.StartDate' => 'required|date',
            'project.EndDate' => 'required|date|after_or_equal:project.StartDate',
            'project.PriorityCode' => 'required|integer|in:1,2,3',

            // Project Status
            'status.ProjectStatusCode' => 'required|string|max:2|in:00,10,11,12,99',
            'status.ProjectStatusReason' => 'nullable|string|max:200',

            // Members (wajib minimal 1)
            'members' => 'required|array|min:1',
            'members.*.UserID' => 'required|integer|exists:users,id',
            'members.*.IsOwner' => 'required|boolean',
            'members.*.Title' => 'nullable|string|max:200',

            // Tasks (opsional)
            'tasks' => 'nullable|array',
            'tasks.*.ParentProjectTaskID' => 'nullable|integer',
            'tasks.*.SequenceNo' => 'nullable|integer',
            'tasks.*.PriorityCode' => 'required|integer|in:1,2,3',
            'tasks.*.TaskDescription' => 'required|string|max:200',
            'tasks.*.StartDate' => 'required|date',
            'tasks.*.EndDate' => 'required|date|after_or_equal:tasks.*.StartDate',
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
            'tasks.*.assignedMembers.*' => 'integer|exists:users,id',

            // Expenses (opsional)
            'expenses' => 'nullable|array',
            'expenses.*.ExpenseDate' => 'required|date',
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
            $authUser   = $request->auth_user;
            $authUserId = $request->auth_user_id;

            // Sesuaikan field admin sesuai User model
            $isAdmin = (bool) ($authUser->IsAdministrator ?? false);

            // ----------------------------------------
            // BASE QUERY
            // ----------------------------------------
            $query = Project::query()
                ->where('IsDelete', false)
                ->with('status');

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

            // ----------------------------------------
            // FETCH PROJECTS
            // ----------------------------------------
            $projects = $query
                ->orderBy('AtTimeStamp', 'desc')
                ->get();

            // ----------------------------------------
            // MAP RESPONSE
            // ----------------------------------------
            $data = $projects->map(function ($project) use ($authUserId, $isAdmin) {

                $member = null;

                if (!$isAdmin) {
                    $member = ProjectMember::where('ProjectID', $project->ProjectID)
                        ->where('UserID', $authUserId)
                        ->where('IsActive', true)
                        ->first();
                }

                return [
                    'ProjectID' => $project->ProjectID,
                    'ProjectDescription' => $project->ProjectDescription,
                    'StartDate' => $project->StartDate,
                    'EndDate' => $project->EndDate,
                    'PriorityCode' => $project->PriorityCode,

                    'Status' => [
                        'ProjectStatusCode' => $project->status->ProjectStatusCode ?? null,
                        'TotalMember' => $project->status->TotalMember ?? 0,
                        'TotalTask' => $project->status->TotalTask ?? 0,
                        'TotalExpense' => $project->status->TotalExpense ?? 0,
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
                'data' => $data,
            ], 200);
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
                : ['members', 'tasks', 'expenses'];

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

            // =======================
            // TASKS
            // =======================
            if (in_array('tasks', $includes)) {
                $relations['tasks'] = function ($q) use (
                    $taskStatus,
                    $isAdmin,
                    $authUserId
                ) {
                    // Status filter
                    if ($taskStatus === 'active') {
                        $q->where('IsDelete', false);
                    } elseif ($taskStatus === 'deleted') {
                        $q->where('IsDelete', true);
                    }

                    // 🔐 EMPLOYEE: hanya task yang di-assign ke dia
                    if (!$isAdmin) {
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
            'CurrencyCode' => 'nullable|string|max:3',
            'BudgetAmount' => 'nullable|numeric',
            'StartDate' => 'nullable|date',
            'EndDate' => 'nullable|date|after_or_equal:StartDate',
            'PriorityCode' => 'nullable|integer|in:1,2,3',
            'ProjectStatusCode' => 'nullable|string|max:2|in:00,10,11,12,99',
            'ProjectStatusReason' => 'nullable|string|max:200',
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

            if (!$isOwner) {
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

            if ($request->has('ProjectDescription')) {
                $updateData['ProjectDescription'] = $request->ProjectDescription;
            }
            if ($request->has('ProjectCategoryID')) {
                $updateData['ProjectCategoryID'] = $request->ProjectCategoryID;
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

            $project->update($updateData);

            // Update Project Status if provided
            if ($request->has('ProjectStatusCode')) {
                ProjectStatus::where('ProjectID', $projectId)->update([
                    'ProjectStatusCode' => $request->ProjectStatusCode,
                    'ProjectStatusReason' => $request->ProjectStatusReason ?? null,
                ]);
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
                'Note' => 'Project updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Project updated successfully',
                'data' => $project->fresh(['status']),
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
     * Delete Project (Soft Delete)
     */
    public function destroy(Request $request, $projectId)
    {
        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $timestamp = Carbon::now()->timestamp;

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

            if (!$isOwner) {
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
                'Note' => 'Project deleted (soft delete)'
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
            'UserID' => 'required|integer|exists:users,id',
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

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner) {
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

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner) {
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
                'Note' => 'Project member updated'
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
            'StartDate' => 'required|date',
            'EndDate' => 'required|date|after_or_equal:StartDate',
            'ProgressCode' => 'required|integer|in:0,1,2',
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
            'assignedMembers.*' => 'integer|exists:users,id',
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

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can add tasks',
                ], 403);
            }

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
                'ProgressCode' => $request->ProgressCode,
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
                    'ProgressCode' => $request->ProgressCode,
                    'TotalFiles' => count($taskFileUrls),
                    'AssignedMembers' => $assignedMembers,
                ]),
                'Note' => 'Project task added'
            ]);

            DB::commit();

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
            'StartDate' => 'nullable|date',
            'EndDate' => 'nullable|date',
            'ProgressCode' => 'nullable|integer|in:0,1,2',
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
            'assignedMembers.*' => 'integer|exists:users,id',
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

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can edit tasks',
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

            // Update task
            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

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
            if ($request->has('ProgressCode')) {
                $updateData['ProgressCode'] = $request->ProgressCode;
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
                    'new_files_count' => count($newTaskFileUrls),
                    'deleted_files_count' => count($request->delete_files ?? []),
                    'assigned_members' => $assignedMembers,
                ]),
                'Note' => 'Project task updated'
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
     * Delete Project Task (Soft Delete)
     */
    public function deleteTask(Request $request, $projectId, $taskId)
    {
        DB::beginTransaction();
        try {
            $authUserId = $request->auth_user_id;
            $timestamp = Carbon::now()->timestamp;

            // Check if user is owner
            $isOwner = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsOwner', true)
                ->where('IsActive', true)
                ->exists();

            if (!$isOwner) {
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
                'Note' => 'Project task deleted (soft delete)'
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
            'ExpenseDate' => 'required|date',
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

            // Check if user is member
            $isMember = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsActive', true)
                ->exists();

            if (!$isMember) {
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
            'ExpenseDate' => 'nullable|date',
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

            // Check if user is owner (for IsCheck) or member (for other fields)
            $member = ProjectMember::where('ProjectID', $projectId)
                ->where('UserID', $authUserId)
                ->where('IsActive', true)
                ->first();

            if (!$member) {
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
            if ($request->has('files') && !empty($request->files)) {
                foreach ($request->files as $fileData) {
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
                'Note' => 'Project expense updated'
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
                moduleNameId: (string) $projectId . '/TASK',
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
                moduleNameId: (string) $projectId . '/TASK',
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
                moduleNameId: (string) $projectId . '/EXPENSE',
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
                moduleNameId: (string) $projectId . '/EXPENSE',
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
                moduleNameId: (string) $projectId . '/EXPENSE',
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

        // Update status
        $status->update([
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
}
