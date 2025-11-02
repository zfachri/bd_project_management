<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\User;
use App\Models\LoginCheck;
use App\Models\AuditLog;
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
        $organizationId = $request->input('organization_id');
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
        $organizationId = $request->input('organization_id');
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
            'Email' => 'required|email|max:100|unique:user,Email',
            'Password' => 'required|string|min:6',
            'UTCCode' => 'nullable|string|max:6',

            // Employee fields
            'OrganizationID' => 'required|integer|exists:organization,OrganizationID',
            'GenderCode' => 'required|in:M,F',
            'DateOfBirth' => 'nullable|date',
            'JoinDate' => 'required|date',
            'Note' => 'nullable|string|max:200',

            // Position fields
            'Positions' => 'required|array|min:1',
            'Positions.*.PositionID' => 'required|integer|exists:position,PositionID',
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'OrganizationID' => $request->OrganizationID,
                'GenderCode' => $request->GenderCode,
                'DateOfBirth' => $request->DateOfBirth,
                'JoinDate' => $request->JoinDate,
                'ResignDate' => null,
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

            // Step 4: Create Employee Positions
            foreach ($request->Positions as $positionData) {
                EmployeePosition::create([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'OrganizationID' => $request->OrganizationID,
                    'PositionID' => $positionData['PositionID'],
                    'EmployeeID' => $employee->EmployeeID,
                    'StartDate' => $positionData['StartDate'],
                    'EndDate' => $positionData['EndDate'] ?? null,
                    'Note' => $positionData['Note'] ?? null,
                    'IsActive' => true,
                    'IsDelete' => false,
                ]);
            }

            // Step 5: Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'employee',
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

            // Create audit log
            AuditLog::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'employee',
                'ReferenceRecordID' => $employee->EmployeeID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'FullName' => $employee->user->FullName ?? null,
                        'OrganizationID' => $employee->OrganizationID,
                        'GenderCode' => $employee->GenderCode,
                    ]
                ]),
                'Note' => 'Employee updated'
            ]);

            DB::commit();

            // Reload with relationships
            $employee->load(['user', 'organization']);

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
            'PositionID' => 'required|integer|exists:position,PositionID',
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

            $employeePosition = EmployeePosition::create([
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'employee_position',
                'ReferenceRecordID' => $employeePosition->EmployeePositionID,
                'Data' => json_encode([
                    'EmployeeID' => $employee->EmployeeID,
                    'PositionID' => $request->PositionID,
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

        $validator = Validator::make($request->all(), [
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

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'employee_position',
                'ReferenceRecordID' => $employeePosition->EmployeePositionID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'employee_position',
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'employee',
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
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'employee',
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
}
