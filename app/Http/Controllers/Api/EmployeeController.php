<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\User;
use App\Models\LoginCheck;
use App\Models\AuditLog;
use App\Models\Position;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmployeeController extends Controller
{
    /**
     * Get all employees
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $organizationId = $request->input('OrganizationID');
        // $positionId = $request->input('PositionID');
        $status = $request->input('status'); // active, resigned

        $query = Employee::with(['organization', 'user', 'currentPosition.position']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('FullName', 'like', "%{$search}%")
                        ->orWhere('Email', 'like', "%{$search}%");
                })
                    ->orWhere('EmployeeID', $search);
            });
        }

        if ($positionId) {
            $query->where('PositionID', $positionId);
        }

        if ($organizationId) {
            $query->where('OrganizationID', $organizationId);
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'resigned') {
            $query->whereNotNull('ResignDate');
        }

        $employees = $query->paginate($perPage);

        $employees->getCollection()->transform(function ($employee) {
            $currentPosition = $employee->currentPosition;

            return [
                'EmployeeID' => $employee->EmployeeID,
                'FullName' => $employee->user->FullName ?? null,
                'Email' => $employee->user->Email ?? null,
                'OrganizationID' => $employee->OrganizationID,
                'OrganizationName' => $employee->organization->OrganizationName ?? null,
                'CurrentPosition' => $currentPosition ? [
                    'PositionID' => $currentPosition->PositionID,
                    'PositionName' => $currentPosition->position->PositionName ?? null,
                    'StartDate' => $currentPosition->StartDate,
                ] : null,
                'GenderCode' => $employee->GenderCode,
                'DateOfBirth' => $employee->DateOfBirth,
                'JoinDate' => $employee->JoinDate,
                'ResignDate' => $employee->ResignDate,
                'IsActive' => is_null($employee->ResignDate),
                'CreatedAt' => $employee->AtTimeStamp,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully',
            'data' => $employees
        ], 200);
    }

    /**
     * Get all employees without pagination
     */
    public function all(Request $request)
    {
        $organizationId = $request->input('OrganizationID');
        $status = $request->input('status', 'active');

        $query = Employee::with(['user', 'organization']);

        if ($organizationId) {
            $query->where('OrganizationID', $organizationId);
        }

        if ($status === 'active') {
            $query->active();
        }

        $employees = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully',
            'data' => $employees
        ], 200);
    }

    /**
     * Get single employee with positions
     */
    public function show($id)
    {
        $employee = Employee::with([
            'user',
            'organization',
            'employeePositions' => function ($query) {
                $query->active()->with('position.positionLevel')->orderBy('StartDate', 'desc');
            }
        ])->find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee retrieved successfully',
            'data' => [
                'EmployeeID' => $employee->EmployeeID,
                'User' => $employee->user ? [
                    'UserID' => $employee->user->UserID,
                    'FullName' => $employee->user->FullName,
                    'Email' => $employee->user->Email,
                    'UTCCode' => $employee->user->UTCCode,
                ] : null,
                'OrganizationID' => $employee->OrganizationID,
                'OrganizationName' => $employee->organization->OrganizationName ?? null,
                'GenderCode' => $employee->GenderCode,
                'DateOfBirth' => $employee->DateOfBirth,
                'JoinDate' => $employee->JoinDate,
                'ResignDate' => $employee->ResignDate,
                'Note' => $employee->Note,
                'IsDelete' => $employee->IsDelete,
                'Positions' => $employee->employeePositions->map(function ($empPos) {
                    return [
                        'EmployeePositionID' => $empPos->EmployeePositionID,
                        'PositionID' => $empPos->PositionID,
                        'PositionName' => $empPos->position->PositionName ?? null,
                        'PositionLevelName' => $empPos->position->positionLevel->PositionLevelName ?? null,
                        'StartDate' => $empPos->StartDate,
                        'EndDate' => $empPos->EndDate,
                        'IsActive' => $empPos->IsActive,
                        'IsCurrent' => is_null($empPos->EndDate),
                    ];
                }),
                'CreatedAt' => $employee->AtTimeStamp,
            ]
        ], 200);
    }

    /**
     * Create new employee with user and position
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // User fields
            'FullName' => 'required|string|max:100',
            'Email' => 'required|email|max:100|unique:User,Email',
            'Password' => 'required|string|min:6',
            'UTCCode' => 'nullable|string|max:6',

            // Employee fields
            'EmployeeID' => 'required|integer|unique:Employee,EmployeeID',
            'OrganizationID' => 'required|integer|exists:Organization,OrganizationID',
            'GenderCode' => 'required|in:M,F',
            'DateOfBirth' => 'nullable|date',
            'JoinDate' => 'required|date',
            'Note' => 'nullable|string|max:200',
            'ResignDate' => 'nullable|date',

            // Position fields
            'Positions' => 'required|array|min:1',
            'Positions.*.PositionID' => 'nullable|integer|exists:Position,PositionID',
            'Positions.*.PositionName' => 'required|string',
            'Positions.*.ParentPositionID' => 'nullable|string',
            'Positions.*.PositionLevelID' => 'nullable|integer',
            'Positions.*.IsChild' => 'nullable|boolean',
            // 'Positions.*.LevelNo' => 'nullable|integer',
            // 'Positions.*.RequirementQuantity' => 'nullable|integer',
            'Positions.*.StartDate' => 'required|date',
            'Positions.*.EndDate' => 'nullable|date|after:Positions.*.StartDate',
            'Positions.*.Note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Step 1: Create Employee
            $employee = Employee::create([
                'EmployeeID' => $request->EmployeeID,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'OrganizationID' => $request->OrganizationID,
                'GenderCode' => $request->GenderCode,
                'DateOfBirth' => $request->DateOfBirth,
                'JoinDate' => $request->JoinDate,
                'ResignDate' => $request->ResignDate,
                'Note' => $request->Note,
                'IsDelete' => false,
            ]);

            // Step 2: Create User with EmployeeID as UserID
            $salt = Str::uuid()->toString();

            $user = User::create([
                'UserID' => $employee->EmployeeID, // UserID = EmployeeID
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'IsAdministrator' => false, // IsAdministrator = 0
                'FullName' => $request->FullName,
                'Email' => $request->Email,
                'Password' => Hash::make($request->Password . $salt),
                'UTCCode' => $request->UTCCode ?? '+07:00',
            ]);

            // Step 3: Create LoginCheck
            LoginCheck::create([
                'UserID' => $user->UserID,
                'UserStatusCode' => '11', // New
                'IsChangePassword' => true, // Must change password on first login
                'Salt' => $salt,
                'LastLoginTimeStamp' => null,
                'LastLoginLocationJSON' => null,
                'LastLoginAttemptCounter' => 0,
            ]);

            $createdPositions = [];

            foreach ($request->Positions as $positionData) {
                $positionId = null;

                // Check if PositionID exists and is valid
                if (!empty($positionData['PositionID'])) {
                    $existingPosition = Position::where('PositionID', $positionData['PositionID'])
                        ->where('IsDelete', false)
                        ->first();

                    if ($existingPosition) {
                        // Use existing position
                        $positionId = $existingPosition->PositionID;
                    }
                }

                // If position doesn't exist, create new one
                if (!$positionId) {
                    // Determine LevelNo and IsChild
                    $levelNo = $positionData['LevelNo'] ?? 1;
                    $isChild = $positionData['IsChild'] ?? false;

                    if (!empty($positionData['ParentPositionID'])) {
                        $parentPosition = Position::find($positionData['ParentPositionID']);
                        if ($parentPosition) {
                            $levelNo = $parentPosition->LevelNo + 1;
                            $isChild = true;
                        }
                    }

                    $newPositionId = Carbon::now()->timestamp . random_numbersu(5);

                    $newPosition = Position::create([
                        'PositionID' => $newPositionId,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'OrganizationID' => $request->OrganizationID,
                        'ParentPositionID' => $positionData['ParentPositionID'] ?? null,
                        'LevelNo' => $levelNo,
                        'IsChild' => $isChild,
                        'PositionName' => $positionData['PositionName'],
                        'PositionLevelID' => $positionData['PositionLevelID'],
                        'RequirementQuantity' => 3,
                        'IsActive' => true,
                        'IsDelete' => false,
                    ]);

                    // If no parent, set ParentPositionID to itself
                    if (!$newPosition->ParentPositionID) {
                        $newPosition->ParentPositionID = $newPositionId;
                        $newPosition->save();
                    }

                    $positionId = $newPositionId;

                    // Log position creation
                    AuditLog::create([
                        'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'Position',
                        'ReferenceRecordID' => $positionId,
                        'Data' => json_encode([
                            'PositionName' => $positionData['PositionName'],
                            'OrganizationID' => $request->OrganizationID,
                            'CreatedDuringEmployeeRegistration' => true,
                        ]),
                        'Note' => 'Position created during employee registration'
                    ]);
                }
                $empPositionId = Carbon::now()->timestamp . random_numbersu(5);

                $employeePosition = EmployeePosition::create([
                    'EmployeePositionID' => $empPositionId,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'OrganizationID' => $request->OrganizationID,
                    'PositionID' => $positionId,
                    'EmployeeID' => $employee->EmployeeID,
                    'StartDate' => $positionData['StartDate'],
                    'EndDate' => $positionData['EndDate'] ?? null,
                    'Note' => $positionData['Note'] ?? null,
                    'IsActive' => true,
                    'IsDelete' => false,
                ]);

                $createdPositions[] = $employeePosition;
            }

            // // Step 4: Create Employee Positions
            // foreach ($request->Positions as $positionData) {
            //     EmployeePosition::create([
            //         'AtTimeStamp' => $timestamp,
            //         'ByUserID' => $authUserId,
            //         'OperationCode' => 'I',
            //         'OrganizationID' => $request->OrganizationID,
            //         'PositionID' => $positionData['PositionID'],
            //         'EmployeeID' => $employee->EmployeeID,
            //         'StartDate' => $positionData['StartDate'],
            //         'EndDate' => $positionData['EndDate'] ?? null,
            //         'Note' => $positionData['Note'] ?? null,
            //         'IsActive' => true,
            //         'IsDelete' => false,
            //     ]);
            // }

            // Step 5: Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'Employee',
                'ReferenceRecordID' => $employee->EmployeeID,
                'Data' => json_encode([
                    'EmployeeID' => $employee->EmployeeID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'OrganizationID' => $employee->OrganizationID,
                    'PositionsCount' => count($request->Positions),
                ]),
                'Note' => 'Employee created with user and positions'
            ]);

            DB::commit();

            // Reload with relationships
            $employee->load(['user', 'organization', 'employeePositions.position']);

            return response()->json([
                'success' => true,
                'message' => 'Employee deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update employee
     */
    public function update(Request $request, $id)
    {
        $employee = Employee::with('user')->find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            // User fields
            'FullName' => 'nullable|string|max:100',
            'UTCCode' => 'nullable|string|max:6',

            // Employee fields
            'OrganizationID' => 'nullable|integer|exists:organization,OrganizationID',
            'GenderCode' => 'nullable|in:M,F',
            'DateOfBirth' => 'nullable|date',
            'JoinDate' => 'nullable|date',
            'Note' => 'nullable|string|max:200',

            // Optional: Update positions
            'Positions' => 'nullable|array',
            'Positions.*.EmployeePositionID' => 'nullable|integer',
            'Positions.*.PositionID' => 'nullable|integer|exists:position,PositionID',
            'Positions.*.PositionName' => 'required_with:Positions|string|max:100',
            'Positions.*.ParentPositionID' => 'nullable|integer|exists:position,PositionID',
            'Positions.*.PositionLevelID' => 'required_with:Positions|integer|exists:position_level,PositionLevelID',
            'Positions.*.IsChild' => 'nullable|boolean',
            'Positions.*.LevelNo' => 'nullable|integer',
            'Positions.*.RequirementQuantity' => 'nullable|integer|min:0',
            'Positions.*.StartDate' => 'required_with:Positions|date',
            'Positions.*.EndDate' => 'nullable|date|after:Positions.*.StartDate',
            'Positions.*.Note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
                'FullName' => $employee->user->FullName ?? null,
                'OrganizationID' => $employee->OrganizationID,
                'GenderCode' => $employee->GenderCode,
            ];

            // Update Employee
            $employeeUpdateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('OrganizationID')) {
                $employeeUpdateData['OrganizationID'] = $request->OrganizationID;
            }

            if ($request->has('GenderCode')) {
                $employeeUpdateData['GenderCode'] = $request->GenderCode;
            }

            if ($request->has('DateOfBirth')) {
                $employeeUpdateData['DateOfBirth'] = $request->DateOfBirth;
            }

            if ($request->has('JoinDate')) {
                $employeeUpdateData['JoinDate'] = $request->JoinDate;
            }

            if ($request->has('Note')) {
                $employeeUpdateData['Note'] = $request->Note;
            }

            $employee->update($employeeUpdateData);

            // Update User if fields provided
            if ($employee->user && ($request->has('FullName') || $request->has('UTCCode'))) {
                $userUpdateData = [
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                ];

                if ($request->has('FullName')) {
                    $userUpdateData['FullName'] = $request->FullName;
                }

                if ($request->has('UTCCode')) {
                    $userUpdateData['UTCCode'] = $request->UTCCode;
                }

                $employee->user->update($userUpdateData);
            }

            // Update or Add Positions if provided
            $updatedPositions = [];
            if ($request->has('Positions')) {
                foreach ($request->Positions as $positionData) {
                    $positionId = null;

                    // Check if PositionID exists and is valid
                    if (!empty($positionData['PositionID'])) {
                        $existingPosition = Position::where('PositionID', $positionData['PositionID'])
                            ->where('IsDelete', false)
                            ->first();

                        if ($existingPosition) {
                            $positionId = $existingPosition->PositionID;
                        }
                    }

                    // If position doesn't exist, create new one
                    if (!$positionId) {
                        // Determine LevelNo and IsChild
                        $levelNo = $positionData['LevelNo'] ?? 1;
                        $isChild = $positionData['IsChild'] ?? false;

                        if (!empty($positionData['ParentPositionID'])) {
                            $parentPosition = Position::find($positionData['ParentPositionID']);
                            if ($parentPosition) {
                                $levelNo = $parentPosition->LevelNo + 1;
                                $isChild = true;
                            }
                        }

                        $newPositionId = Carbon::now()->timestamp . random_numbersu(5);

                        $newPosition = Position::create([
                            'PositionID' => $newPositionId,
                            'AtTimeStamp' => $timestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'I',
                            'OrganizationID' => $employee->OrganizationID,
                            'ParentPositionID' => $positionData['ParentPositionID'] ?? null,
                            'LevelNo' => $levelNo,
                            'IsChild' => $isChild,
                            'PositionName' => $positionData['PositionName'],
                            'PositionLevelID' => $positionData['PositionLevelID'],
                            'RequirementQuantity' => $positionData['RequirementQuantity'] ?? 0,
                            'IsActive' => true,
                            'IsDelete' => false,
                        ]);

                        // If no parent, set ParentPositionID to itself
                        if (!$newPosition->ParentPositionID) {
                            $newPosition->ParentPositionID = $newPositionId;
                            $newPosition->save();
                        }

                        $positionId = $newPositionId;

                        // Log position creation
                        AuditLog::create([
                            'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                            'AtTimeStamp' => $timestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'I',
                            'ReferenceTable' => 'Position',
                            'ReferenceRecordID' => $positionId,
                            'Data' => json_encode([
                                'PositionName' => $positionData['PositionName'],
                                'OrganizationID' => $employee->OrganizationID,
                                'CreatedDuringEmployeeUpdate' => true,
                            ]),
                            'Note' => 'Position created during employee update'
                        ]);
                    }

                    // Check if this is update or new position assignment
                    if (!empty($positionData['EmployeePositionID'])) {
                        // Update existing EmployeePosition
                        $empPosition = EmployeePosition::where('EmployeePositionID', $positionData['EmployeePositionID'])
                            ->where('EmployeeID', $id)
                            ->first();

                        if ($empPosition) {
                            $empPosition->update([
                                'AtTimeStamp' => $timestamp,
                                'ByUserID' => $authUserId,
                                'OperationCode' => 'U',
                                'PositionID' => $positionId,
                                'StartDate' => $positionData['StartDate'],
                                'EndDate' => $positionData['EndDate'] ?? null,
                                'Note' => $positionData['Note'] ?? null,
                            ]);
                            $updatedPositions[] = $empPosition;
                        }
                    } else {
                        // Create new EmployeePosition
                        $empPositionId = Carbon::now()->timestamp . random_numbersu(5);

                        $newEmpPosition = EmployeePosition::create([
                            'EmployeePositionID' => $empPositionId,
                            'AtTimeStamp' => $timestamp,
                            'ByUserID' => $authUserId,
                            'OperationCode' => 'I',
                            'OrganizationID' => $employee->OrganizationID,
                            'PositionID' => $positionId,
                            'EmployeeID' => $employee->EmployeeID,
                            'StartDate' => $positionData['StartDate'],
                            'EndDate' => $positionData['EndDate'] ?? null,
                            'Note' => $positionData['Note'] ?? null,
                            'IsActive' => true,
                            'IsDelete' => false,
                        ]);
                        $updatedPositions[] = $newEmpPosition;
                    }
                }
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Employee',
                'ReferenceRecordID' => $employee->EmployeeID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'FullName' => $employee->user->FullName ?? null,
                        'OrganizationID' => $employee->OrganizationID,
                        'GenderCode' => $employee->GenderCode,
                    ],
                    'PositionsUpdated' => count($updatedPositions),
                ]),
                'Note' => 'Employee updated'
            ]);

            DB::commit();


            // Reload with relationships
            $employee->load(['user', 'organization', 'employeePositions.position.positionLevel']);

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully',
                'data' => [
                    'EmployeeID' => $employee->EmployeeID,
                    'FullName' => $employee->user->FullName ?? null,
                    'Email' => $employee->user->Email ?? null,
                    'OrganizationID' => $employee->OrganizationID,
                    'OrganizationName' => $employee->organization->OrganizationName ?? null,
                    'GenderCode' => $employee->GenderCode,
                    'DateOfBirth' => $employee->DateOfBirth,
                    'JoinDate' => $employee->JoinDate,
                    'Positions' => $employee->employeePositions->where('IsDelete', false)->map(function ($empPos) {
                        return [
                            'EmployeePositionID' => $empPos->EmployeePositionID,
                            'PositionID' => $empPos->PositionID,
                            'PositionName' => $empPos->position->PositionName ?? null,
                            'PositionLevelName' => $empPos->position->positionLevel->PositionLevelName ?? null,
                            'StartDate' => $empPos->StartDate,
                            'EndDate' => $empPos->EndDate,
                        ];
                    })->values(),
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add position to employee
     */
    public function addPosition(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'PositionID' => 'nullable|integer|exists:position,PositionID',
            'PositionName' => 'required|string|max:100',
            'ParentPositionID' => 'nullable|integer|exists:position,PositionID',
            'PositionLevelID' => 'required|integer|exists:position_level,PositionLevelID',
            'IsChild' => 'nullable|boolean',
            'LevelNo' => 'nullable|integer',
            'RequirementQuantity' => 'nullable|integer|min:0',
            'StartDate' => 'required|date',
            'EndDate' => 'nullable|date|after:StartDate',
            'Note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $positionId = null;

            // Check if PositionID exists and is valid
            if (!empty($request->PositionID)) {
                $existingPosition = Position::where('PositionID', $request->PositionID)
                    ->where('IsDelete', false)
                    ->first();

                if ($existingPosition) {
                    // Use existing position
                    $positionId = $existingPosition->PositionID;
                }
            }

            // If position doesn't exist, create new one
            if (!$positionId) {
                // Determine LevelNo and IsChild
                $levelNo = $request->LevelNo ?? 1;
                $isChild = $request->IsChild ?? false;

                if (!empty($request->ParentPositionID)) {
                    $parentPosition = Position::find($request->ParentPositionID);
                    if ($parentPosition) {
                        $levelNo = $parentPosition->LevelNo + 1;
                        $isChild = true;
                    }
                }

                $newPositionId = Carbon::now()->timestamp . random_numbersu(5);

                $newPosition = Position::create([
                    'PositionID' => $newPositionId,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'OrganizationID' => $employee->OrganizationID,
                    'ParentPositionID' => $request->ParentPositionID ?? null,
                    'LevelNo' => $levelNo,
                    'IsChild' => $isChild,
                    'PositionName' => $request->PositionName,
                    'PositionLevelID' => $request->PositionLevelID,
                    'RequirementQuantity' => $request->RequirementQuantity ?? 0,
                    'IsActive' => true,
                    'IsDelete' => false,
                ]);

                // If no parent, set ParentPositionID to itself
                if (!$newPosition->ParentPositionID) {
                    $newPosition->ParentPositionID = $newPositionId;
                    $newPosition->save();
                }

                $positionId = $newPositionId;

                // Log position creation
                AuditLog::create([
                    'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'ReferenceTable' => 'Position',
                    'ReferenceRecordID' => $positionId,
                    'Data' => json_encode([
                        'PositionName' => $request->PositionName,
                        'OrganizationID' => $employee->OrganizationID,
                        'CreatedDuringPositionAssignment' => true,
                    ]),
                    'Note' => 'Position created during position assignment to employee'
                ]);
            }

            $empPositionId = Carbon::now()->timestamp . random_numbersu(5);

            $employeePosition = EmployeePosition::create([
                'EmployeePositionID' => $empPositionId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'OrganizationID' => $employee->OrganizationID,
                'PositionID' => $request->PositionID,
                'EmployeeID' => $employee->EmployeeID,
                'StartDate' => $request->StartDate,
                'EndDate' => $request->EndDate,
                'Note' => $request->Note,
                'IsActive' => true,
                'IsDelete' => false,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'EmployeePosition',
                'ReferenceRecordID' => $employeePosition->EmployeePositionID,
                'Data' => json_encode([
                    'EmployeeID' => $employee->EmployeeID,
                    'PositionID' => $positionId,
                    'PositionCreated' => empty($request->PositionID),
                    'StartDate' => $request->StartDate,
                ]),
                'Note' => 'Position added to employee'
            ]);

            // Reload with relationships
            $employeePosition->load('position.positionLevel');

            return response()->json([
                'success' => true,
                'message' => 'Position added successfully',
                'data' => [
                    'EmployeePositionID' => $employeePosition->EmployeePositionID,
                    'PositionID' => $employeePosition->PositionID,
                    'PositionName' => $employeePosition->position->PositionName ?? null,
                    'PositionLevelName' => $employeePosition->position->positionLevel->PositionLevelName ?? null,
                    'StartDate' => $employeePosition->StartDate,
                    'EndDate' => $employeePosition->EndDate,
                    'PositionWasCreated' => empty($request->PositionID),
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update employee position
     */
    public function updatePosition(Request $request, $id, $positionId)
    {
        $employeePosition = EmployeePosition::where('EmployeeID', $id)
            ->where('EmployeePositionID', $positionId)
            ->first();

        if (!$employeePosition) {
            return response()->json([
                'success' => false,
                'message' => 'Employee position not found'
            ], 404);
        }

        $employee = Employee::find($id);

        $validator = Validator::make($request->all(), [
            'PositionID' => 'nullable|integer|exists:position,PositionID',
            'PositionName' => 'nullable|string|max:100',
            'ParentPositionID' => 'nullable|integer|exists:position,PositionID',
            'PositionLevelID' => 'nullable|integer|exists:position_level,PositionLevelID',
            'IsChild' => 'nullable|boolean',
            'LevelNo' => 'nullable|integer',
            'RequirementQuantity' => 'nullable|integer|min:0',
            'StartDate' => 'nullable|date',
            'EndDate' => 'nullable|date|after:StartDate',
            'Note' => 'nullable|string|max:200',
            'IsActive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
                'PositionID' => $employeePosition->PositionID,
                'StartDate' => $employeePosition->StartDate,
                'EndDate' => $employeePosition->EndDate,
                'Note' => $employeePosition->Note,
                'IsActive' => $employeePosition->IsActive,
            ];

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            // Handle Position change/creation
            if ($request->has('PositionID') || $request->has('PositionName')) {
                $newPositionId = null;

                // Check if PositionID exists and is valid
                if (!empty($request->PositionID)) {
                    $existingPosition = Position::where('PositionID', $request->PositionID)
                        ->where('IsDelete', false)
                        ->first();

                    if ($existingPosition) {
                        $newPositionId = $existingPosition->PositionID;
                    }
                }

                // If position doesn't exist and PositionName provided, create new one
                if (!$newPositionId && $request->has('PositionName')) {
                    // PositionLevelID is required to create new position
                    if (!$request->has('PositionLevelID')) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'PositionLevelID is required to create new position'
                        ], 422);
                    }

                    // Determine LevelNo and IsChild
                    $levelNo = $request->LevelNo ?? 1;
                    $isChild = $request->IsChild ?? false;

                    if (!empty($request->ParentPositionID)) {
                        $parentPosition = Position::find($request->ParentPositionID);
                        if ($parentPosition) {
                            $levelNo = $parentPosition->LevelNo + 1;
                            $isChild = true;
                        }
                    }

                    $newPositionIdGenerated = Carbon::now()->timestamp . random_numbersu(5);

                    $newPosition = Position::create([
                        'PositionID' => $newPositionIdGenerated,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'OrganizationID' => $employee->OrganizationID,
                        'ParentPositionID' => $request->ParentPositionID ?? null,
                        'LevelNo' => $levelNo,
                        'IsChild' => $isChild,
                        'PositionName' => $request->PositionName,
                        'PositionLevelID' => $request->PositionLevelID,
                        'RequirementQuantity' => $request->RequirementQuantity ?? 0,
                        'IsActive' => true,
                        'IsDelete' => false,
                    ]);

                    // If no parent, set ParentPositionID to itself
                    if (!$newPosition->ParentPositionID) {
                        $newPosition->ParentPositionID = $newPositionIdGenerated;
                        $newPosition->save();
                    }

                    $newPositionId = $newPositionIdGenerated;

                    // Log position creation
                    AuditLog::create([
                        'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'ReferenceTable' => 'Position',
                        'ReferenceRecordID' => $newPositionId,
                        'Data' => json_encode([
                            'PositionName' => $request->PositionName,
                            'OrganizationID' => $employee->OrganizationID,
                            'CreatedDuringPositionUpdate' => true,
                        ]),
                        'Note' => 'Position created during employee position update'
                    ]);
                }

                if ($newPositionId) {
                    $updateData['PositionID'] = $newPositionId;
                }
            }

            if ($request->has('StartDate')) {
                $updateData['StartDate'] = $request->StartDate;
            }

            if ($request->has('EndDate')) {
                $updateData['EndDate'] = $request->EndDate;
            }

            if ($request->has('Note')) {
                $updateData['Note'] = $request->Note;
            }

            if ($request->has('IsActive')) {
                $updateData['IsActive'] = $request->IsActive;
            }

            $employeePosition->update($updateData);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'EmployeePosition',
                'ReferenceRecordID' => $employeePosition->EmployeePositionID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'PositionID' => $employeePosition->PositionID,
                        'StartDate' => $employeePosition->StartDate,
                        'EndDate' => $employeePosition->EndDate,
                        'Note' => $employeePosition->Note,
                        'IsActive' => $employeePosition->IsActive,
                    ]
                ]),
                'Note' => 'Employee position updated'
            ]);

            // Reload with relationships
            $employeePosition->load('position.positionLevel');

            return response()->json([
                'success' => true,
                'message' => 'Position updated successfully',
                'data' => [
                    'EmployeePositionID' => $employeePosition->EmployeePositionID,
                    'PositionID' => $employeePosition->PositionID,
                    'PositionName' => $employeePosition->position->PositionName ?? null,
                    'PositionLevelName' => $employeePosition->position->positionLevel->PositionLevelName ?? null,
                    'StartDate' => $employeePosition->StartDate,
                    'EndDate' => $employeePosition->EndDate,
                    'Note' => $employeePosition->Note,
                    'IsActive' => $employeePosition->IsActive,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove position from employee (soft delete)
     */
    public function removePosition(Request $request, $id, $positionId)
    {
        $employeePosition = EmployeePosition::where('EmployeeID', $id)
            ->where('EmployeePositionID', $positionId)
            ->first();

        if (!$employeePosition) {
            return response()->json([
                'success' => false,
                'message' => 'Employee position not found'
            ], 404);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $employeePosition->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsDelete' => true,
                'IsActive' => false,
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'EmployeePosition',
                'ReferenceRecordID' => $employeePosition->EmployeePositionID,
                'Data' => json_encode([
                    'EmployeeID' => $employeePosition->EmployeeID,
                    'PositionID' => $employeePosition->PositionID,
                ]),
                'Note' => 'Position removed from employee (soft delete)'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Position removed successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove position',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resign employee
     */
    public function resign(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        if ($employee->isResigned()) {
            return response()->json([
                'success' => false,
                'message' => 'Employee already resigned'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'ResignDate' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Update employee resign date
            $employee->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ResignDate' => $request->ResignDate,
            ]);

            // End all active positions
            EmployeePosition::where('EmployeeID', $id)
                ->whereNull('EndDate')
                ->update([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'EndDate' => $request->ResignDate,
                    'IsActive' => false,
                ]);

            // Update user status to blocked
            if ($employee->user) {
                $employee->user->loginCheck->update([
                    'UserStatusCode' => '00', // Blocked
                ]);
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Employee',
                'ReferenceRecordID' => $employee->EmployeeID,
                'Data' => json_encode([
                    'ResignDate' => $request->ResignDate,
                ]),
                'Note' => 'Employee resigned'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Employee resigned successfully',
                'data' => [
                    'EmployeeID' => $employee->EmployeeID,
                    'ResignDate' => $employee->ResignDate,
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to resign employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete employee
     */
    public function destroy(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Soft delete employee
            $employee->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsDelete' => true,
            ]);

            // Soft delete all positions
            EmployeePosition::where('EmployeeID', $id)
                ->update([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'IsDelete' => true,
                    'IsActive' => false,
                ]);

            // Block user
            if ($employee->user) {
                $employee->user->loginCheck->update([
                    'UserStatusCode' => '00', // Blocked
                ]);
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'Employee',
                'ReferenceRecordID' => $employee->EmployeeID,
                'Data' => json_encode([
                    'EmployeeID' => $employee->EmployeeID,
                    'FullName' => $employee->user->FullName ?? null,
                ]),
                'Note' => 'Employee deleted (soft delete)'
            ]);

            DB::commit();
            $user = $employee->user;

            return response()->json([
                'success' => true,
                'message' => 'Employee created successfully',
                'data' => [
                    'EmployeeID' => $employee->EmployeeID,
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'OrganizationName' => $employee->organization->OrganizationName ?? null,
                    'Positions' => $employee->employeePositions->map(function ($empPos) {
                        return [
                            'PositionID' => $empPos->PositionID,
                            'PositionName' => $empPos->position->PositionName ?? null,
                            'StartDate' => $empPos->StartDate,
                            'EndDate' => $empPos->EndDate,
                        ];
                    }),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create employee',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get employee hierarchy (bosses and subordinates)
     * GET /api/employees/{id}/hierarchy
     */
    public function getHierarchy(Request $request, $id)
    {
        $employee = Employee::with([
            'currentPosition.position.organization',
            'user'
        ])->find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        $currentPosition = $employee->currentPosition;

        if (!$currentPosition || !$currentPosition->position) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has no active position'
            ], 404);
        }

        $position = $currentPosition->position;
        $organizationId = $position->OrganizationID;

        // Get boss chain (upward hierarchy)
        $bosses = $this->getBossChain($position, $organizationId);

        // Get subordinates (downward hierarchy)
        $subordinates = $this->getSubordinates($position, $organizationId);

        // Get current employee detail
        $currentEmployee = [
            'EmployeeID' => $employee->EmployeeID,
            'FullName' => $employee->user->FullName ?? null,
            'Email' => $employee->user->Email ?? null,
            'Position' => [
                'PositionID' => $position->PositionID,
                'PositionName' => $position->PositionName,
                'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                'LevelNo' => $position->LevelNo,
            ],
            'IsCurrent' => true,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Employee hierarchy retrieved successfully',
            'data' => [
                'Employee' => $currentEmployee,
                'Bosses' => $bosses,
                'Subordinates' => $subordinates,
                'OrganizationID' => $organizationId,
                'OrganizationName' => $position->organization->OrganizationName ?? null,
            ]
        ], 200);
    }

    /**
     * Get boss chain recursively (upward)
     */
    private function getBossChain($position, $organizationId, &$visited = [])
    {
        $bosses = [];

        // Prevent infinite loop
        if (in_array($position->PositionID, $visited)) {
            return $bosses;
        }

        $visited[] = $position->PositionID;

        // Check if has parent and parent is not itself
        if ($position->ParentPositionID && $position->ParentPositionID != $position->PositionID) {
            $parentPosition = Position::with(['positionLevel', 'organization'])
                ->where('PositionID', $position->ParentPositionID)
                ->where('OrganizationID', $organizationId)
                ->where('IsDelete', false)
                ->first();

            if ($parentPosition) {
                // Get employee(s) in this parent position
                $employeesInPosition = EmployeePosition::with(['employee.user'])
                    ->where('PositionID', $parentPosition->PositionID)
                    ->where('OrganizationID', $organizationId)
                    ->active()
                    ->whereNull('EndDate')
                    ->get();

                $employees = $employeesInPosition->map(function ($empPos) use ($parentPosition) {
                    return [
                        'EmployeeID' => $empPos->employee->EmployeeID,
                        'FullName' => $empPos->employee->user->FullName ?? null,
                        'Email' => $empPos->employee->user->Email ?? null,
                        'Position' => [
                            'PositionID' => $parentPosition->PositionID,
                            'PositionName' => $parentPosition->PositionName,
                            'PositionLevelName' => $parentPosition->positionLevel->PositionLevelName ?? null,
                            'LevelNo' => $parentPosition->LevelNo,
                        ],
                        'IsBoss' => true,
                    ];
                })->toArray();

                if (count($employees) > 0) {
                    $bosses[] = [
                        'Position' => [
                            'PositionID' => $parentPosition->PositionID,
                            'PositionName' => $parentPosition->PositionName,
                            'PositionLevelName' => $parentPosition->positionLevel->PositionLevelName ?? null,
                            'LevelNo' => $parentPosition->LevelNo,
                        ],
                        'Employees' => $employees,
                    ];

                    // Recursively get parent's boss
                    $upperBosses = $this->getBossChain($parentPosition, $organizationId, $visited);
                    $bosses = array_merge($bosses, $upperBosses);
                }
            }
        }

        return $bosses;
    }

    /**
     * Get subordinates recursively (downward)
     */
    private function getSubordinates($position, $organizationId, &$visited = [])
    {
        $subordinates = [];

        // Prevent infinite loop
        if (in_array($position->PositionID, $visited)) {
            return $subordinates;
        }

        $visited[] = $position->PositionID;

        // Get all child positions
        $childPositions = Position::with(['positionLevel'])
            ->where('ParentPositionID', $position->PositionID)
            ->where('PositionID', '!=', $position->PositionID) // Exclude self-reference
            ->where('OrganizationID', $organizationId)
            ->where('IsDelete', false)
            ->get();

        foreach ($childPositions as $childPosition) {
            // Get employees in this child position
            $employeesInPosition = EmployeePosition::with(['employee.user'])
                ->where('PositionID', $childPosition->PositionID)
                ->where('OrganizationID', $organizationId)
                ->active()
                ->whereNull('EndDate')
                ->get();

            $employees = $employeesInPosition->map(function ($empPos) use ($childPosition) {
                return [
                    'EmployeeID' => $empPos->employee->EmployeeID,
                    'FullName' => $empPos->employee->user->FullName ?? null,
                    'Email' => $empPos->employee->user->Email ?? null,
                    'Position' => [
                        'PositionID' => $childPosition->PositionID,
                        'PositionName' => $childPosition->PositionName,
                        'PositionLevelName' => $childPosition->positionLevel->PositionLevelName ?? null,
                        'LevelNo' => $childPosition->LevelNo,
                    ],
                    'IsSubordinate' => true,
                ];
            })->toArray();

            if (count($employees) > 0) {
                $subordinates[] = [
                    'Position' => [
                        'PositionID' => $childPosition->PositionID,
                        'PositionName' => $childPosition->PositionName,
                        'PositionLevelName' => $childPosition->positionLevel->PositionLevelName ?? null,
                        'LevelNo' => $childPosition->LevelNo,
                    ],
                    'Employees' => $employees,
                ];
            }

            // Recursively get subordinates of this child position
            $deeperSubordinates = $this->getSubordinates($childPosition, $organizationId, $visited);
            $subordinates = array_merge($subordinates, $deeperSubordinates);
        }

        return $subordinates;
    }

    /**
     * Get organization hierarchy tree
     * GET /api/employees/hierarchy/tree?OrganizationID={id}
     */
    public function getOrganizationHierarchyTree(Request $request)
    {
        $organizationId = $request->input('OrganizationID');

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'OrganizationID is required'
            ], 422);
        }

        $organization = Organization::find($organizationId);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        // Get root positions (positions where ParentPositionID equals PositionID)
        $rootPositions = Position::with(['positionLevel'])
            ->where('OrganizationID', $organizationId)
            ->whereColumn('ParentPositionID', 'PositionID')
            ->where('IsDelete', false)
            ->where('IsActive', true)
            ->get();

        $tree = [];

        foreach ($rootPositions as $rootPosition) {
            $tree[] = $this->buildPositionTree($rootPosition, $organizationId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization hierarchy tree retrieved successfully',
            'data' => [
                'OrganizationID' => $organizationId,
                'OrganizationName' => $organization->OrganizationName,
                'Tree' => $tree,
            ]
        ], 200);
    }

    /**
     * Build position tree recursively
     */
    private function buildPositionTree($position, $organizationId, &$visited = [])
    {
        // Prevent infinite loop
        if (in_array($position->PositionID, $visited)) {
            return null;
        }

        $visited[] = $position->PositionID;

        // Get employees in this position
        $employeesInPosition = EmployeePosition::with(['employee.user'])
            ->where('PositionID', $position->PositionID)
            ->where('OrganizationID', $organizationId)
            ->active()
            ->whereNull('EndDate')
            ->get();

        $employees = $employeesInPosition->map(function ($empPos) {
            return [
                'EmployeeID' => $empPos->employee->EmployeeID,
                'FullName' => $empPos->employee->user->FullName ?? null,
                'Email' => $empPos->employee->user->Email ?? null,
                'StartDate' => $empPos->StartDate,
            ];
        })->toArray();

        // Get child positions
        $childPositions = Position::with(['positionLevel'])
            ->where('ParentPositionID', $position->PositionID)
            ->where('PositionID', '!=', $position->PositionID)
            ->where('OrganizationID', $organizationId)
            ->where('IsDelete', false)
            ->where('IsActive', true)
            ->get();

        $children = [];
        foreach ($childPositions as $childPosition) {
            $childNode = $this->buildPositionTree($childPosition, $organizationId, $visited);
            if ($childNode) {
                $children[] = $childNode;
            }
        }

        return [
            'Position' => [
                'PositionID' => $position->PositionID,
                'PositionName' => $position->PositionName,
                'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                'LevelNo' => $position->LevelNo,
            ],
            'Employees' => $employees,
            'EmployeeCount' => count($employees),
            'Children' => $children,
            'ChildrenCount' => count($children),
        ];
    }
    /**
     * Get employees by position with hierarchy context
     * GET /api/positions/{positionId}/employees
     */
    public function getEmployeesByPosition(Request $request, $positionId)
    {
        $position = Position::with(['positionLevel', 'organization'])
            ->find($positionId);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        // Get employees in this position
        $employeePositions = EmployeePosition::with(['employee.user'])
            ->where('PositionID', $positionId)
            ->where('OrganizationID', $position->OrganizationID)
            ->active()
            ->whereNull('EndDate')
            ->get();

        $employees = $employeePositions->map(function ($empPos) {
            return [
                'EmployeeID' => $empPos->employee->EmployeeID,
                'FullName' => $empPos->employee->user->FullName ?? null,
                'Email' => $empPos->employee->user->Email ?? null,
                'GenderCode' => $empPos->employee->GenderCode,
                'DateOfBirth' => $empPos->employee->DateOfBirth,
                'JoinDate' => $empPos->employee->JoinDate,
                'PositionStartDate' => $empPos->StartDate,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully',
            'data' => [
                'Position' => [
                    'PositionID' => $position->PositionID,
                    'PositionName' => $position->PositionName,
                    'PositionLevel' => [
                        'PositionLevelID' => $position->positionLevel->PositionLevelID ?? null,
                        'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                    ],
                    'LevelNo' => $position->LevelNo,
                    'RequirementQuantity' => $position->RequirementQuantity,
                ],
                'OrganizationID' => $position->OrganizationID,
                'OrganizationName' => $position->organization->OrganizationName ?? null,
                'Employees' => $employees,
                'EmployeeCount' => $employees->count(),
            ]
        ], 200);
    }

    /**
     * Get position hierarchy with all employees at each level
     * GET /api/positions/{positionId}/hierarchy/employees
     */
    public function getPositionHierarchyWithEmployees(Request $request, $positionId)
    {
        $position = Position::with(['positionLevel', 'organization'])
            ->find($positionId);

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found'
            ], 404);
        }

        $organizationId = $position->OrganizationID;

        // Get parent chain with employees
        $parents = $this->getPositionParentChainWithEmployees($position, $organizationId);

        // Get current position employees
        $currentEmployees = $this->getEmployeesInPosition($position->PositionID, $organizationId);

        // Get children positions with employees (recursive)
        $children = $this->getPositionChildrenWithEmployees($position, $organizationId);

        return response()->json([
            'success' => true,
            'message' => 'Position hierarchy with employees retrieved successfully',
            'data' => [
                'Position' => [
                    'PositionID' => $position->PositionID,
                    'PositionName' => $position->PositionName,
                    'PositionLevel' => [
                        'PositionLevelID' => $position->positionLevel->PositionLevelID ?? null,
                        'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                    ],
                    'LevelNo' => $position->LevelNo,
                    'RequirementQuantity' => $position->RequirementQuantity,
                ],
                'Employees' => $currentEmployees,
                'EmployeeCount' => count($currentEmployees),
                'Parents' => $parents,
                'Children' => $children,
                'OrganizationID' => $organizationId,
                'OrganizationName' => $position->organization->OrganizationName ?? null,
            ]
        ], 200);
    }

    /**
     * Get all employees in organization grouped by position hierarchy
     * GET /api/organizations/{organizationId}/employees/by-position
     */
    public function getEmployeesByPositionHierarchy(Request $request, $organizationId)
    {
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        // Get root positions
        $rootPositions = Position::with(['positionLevel'])
            ->where('OrganizationID', $organizationId)
            ->whereColumn('ParentPositionID', 'PositionID')
            ->where('IsDelete', false)
            ->where('IsActive', true)
            ->get();

        $tree = [];
        foreach ($rootPositions as $rootPosition) {
            $tree[] = $this->buildPositionTreeWithEmployees($rootPosition, $organizationId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Employees by position hierarchy retrieved successfully',
            'data' => [
                'OrganizationID' => $organizationId,
                'OrganizationName' => $organization->OrganizationName,
                'PositionTree' => $tree,
            ]
        ], 200);
    }

    /**
     * Helper: Get employees in a specific position
     */
    private function getEmployeesInPosition($positionId, $organizationId)
    {
        $employeePositions = EmployeePosition::with(['employee.user'])
            ->where('PositionID', $positionId)
            ->where('OrganizationID', $organizationId)
            ->active()
            ->whereNull('EndDate')
            ->get();

        return $employeePositions->map(function ($empPos) {
            return [
                'EmployeeID' => $empPos->employee->EmployeeID,
                'FullName' => $empPos->employee->user->FullName ?? null,
                'Email' => $empPos->employee->user->Email ?? null,
                'GenderCode' => $empPos->employee->GenderCode,
                'JoinDate' => $empPos->employee->JoinDate,
                'PositionStartDate' => $empPos->StartDate,
            ];
        })->toArray();
    }

    /**
     * Helper: Get parent chain with employees
     */
    private function getPositionParentChainWithEmployees($position, $organizationId, &$visited = [])
    {
        $parents = [];

        if (in_array($position->PositionID, $visited)) {
            return $parents;
        }

        $visited[] = $position->PositionID;

        if ($position->ParentPositionID && $position->ParentPositionID != $position->PositionID) {
            $parentPosition = Position::with(['positionLevel'])
                ->where('PositionID', $position->ParentPositionID)
                ->where('OrganizationID', $organizationId)
                ->where('IsDelete', false)
                ->first();

            if ($parentPosition) {
                $employees = $this->getEmployeesInPosition($parentPosition->PositionID, $organizationId);

                $parents[] = [
                    'Position' => [
                        'PositionID' => $parentPosition->PositionID,
                        'PositionName' => $parentPosition->PositionName,
                        'PositionLevel' => [
                            'PositionLevelID' => $parentPosition->positionLevel->PositionLevelID ?? null,
                            'PositionLevelName' => $parentPosition->positionLevel->PositionLevelName ?? null,
                        ],
                        'LevelNo' => $parentPosition->LevelNo,
                    ],
                    'Employees' => $employees,
                    'EmployeeCount' => count($employees),
                ];

                $upperParents = $this->getPositionParentChainWithEmployees($parentPosition, $organizationId, $visited);
                $parents = array_merge($parents, $upperParents);
            }
        }

        return $parents;
    }

    /**
     * Helper: Get children positions with employees (recursive)
     */
    private function getPositionChildrenWithEmployees($position, $organizationId, &$visited = [])
    {
        $children = [];

        if (in_array($position->PositionID, $visited)) {
            return $children;
        }

        $visited[] = $position->PositionID;

        $childPositions = Position::with(['positionLevel'])
            ->where('ParentPositionID', $position->PositionID)
            ->where('PositionID', '!=', $position->PositionID)
            ->where('OrganizationID', $organizationId)
            ->where('IsDelete', false)
            ->where('IsActive', true)
            ->get();

        foreach ($childPositions as $childPosition) {
            $employees = $this->getEmployeesInPosition($childPosition->PositionID, $organizationId);

            $childData = [
                'Position' => [
                    'PositionID' => $childPosition->PositionID,
                    'PositionName' => $childPosition->PositionName,
                    'PositionLevel' => [
                        'PositionLevelID' => $childPosition->positionLevel->PositionLevelID ?? null,
                        'PositionLevelName' => $childPosition->positionLevel->PositionLevelName ?? null,
                    ],
                    'LevelNo' => $childPosition->LevelNo,
                ],
                'Employees' => $employees,
                'EmployeeCount' => count($employees),
                'Children' => $this->getPositionChildrenWithEmployees($childPosition, $organizationId, $visited),
            ];

            $children[] = $childData;
        }

        return $children;
    }

    /**
     * Helper: Build position tree with employees (recursive)
     */
    private function buildPositionTreeWithEmployees($position, $organizationId, &$visited = [])
    {
        if (in_array($position->PositionID, $visited)) {
            return null;
        }

        $visited[] = $position->PositionID;

        $employees = $this->getEmployeesInPosition($position->PositionID, $organizationId);

        $childPositions = Position::with(['positionLevel'])
            ->where('ParentPositionID', $position->PositionID)
            ->where('PositionID', '!=', $position->PositionID)
            ->where('OrganizationID', $organizationId)
            ->where('IsDelete', false)
            ->where('IsActive', true)
            ->get();

        $children = [];
        foreach ($childPositions as $childPosition) {
            $childNode = $this->buildPositionTreeWithEmployees($childPosition, $organizationId, $visited);
            if ($childNode) {
                $children[] = $childNode;
            }
        }

        return [
            'Position' => [
                'PositionID' => $position->PositionID,
                'PositionName' => $position->PositionName,
                'PositionLevel' => [
                    'PositionLevelID' => $position->positionLevel->PositionLevelID ?? null,
                    'PositionLevelName' => $position->positionLevel->PositionLevelName ?? null,
                ],
                'LevelNo' => $position->LevelNo,
                'RequirementQuantity' => $position->RequirementQuantity,
            ],
            'Employees' => $employees,
            'EmployeeCount' => count($employees),
            'Children' => $children,
        ];
    }
}
