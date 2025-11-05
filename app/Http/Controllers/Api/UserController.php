<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoginCheck;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * Get all users
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');

        $query = User::with('loginCheck');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('FullName', 'like', "%{$search}%")
                  ->orWhere('Email', 'like', "%{$search}%")
                  ->orWhere('UserID', $search);
            });
        }

        $users = $query->paginate($perPage);

        $users->getCollection()->transform(function($user) {
            return [
                'UserID' => $user->UserID,
                'FullName' => $user->FullName,
                'Email' => $user->Email,
                'IsAdministrator' => $user->IsAdministrator,
                'UTCCode' => $user->UTCCode,
                'Status' => [
                    'code' => $user->loginCheck->UserStatusCode ?? null,
                    'label' => $this->getStatusLabel($user->loginCheck->UserStatusCode ?? null),
                ],
                'IsChangePassword' => $user->loginCheck->IsChangePassword ?? null,
                'LastLogin' => $user->loginCheck->LastLoginTimeStamp ?? null,
                'LoginAttemptCounter' => $user->loginCheck->LastLoginAttemptCounter ?? 0,
                'CreatedAt' => $user->AtTimeStamp,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users
        ], 200);
    }

    /**
     * Get single user
     */
    public function show($id)
    {
        $user = User::with('loginCheck')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => [
                'UserID' => $user->UserID,
                'FullName' => $user->FullName,
                'Email' => $user->Email,
                'IsAdministrator' => $user->IsAdministrator,
                'UTCCode' => $user->UTCCode,
                'Status' => [
                    'Code' => $user->loginCheck->UserStatusCode ?? null,
                    'Label' => $this->getStatusLabel($user->loginCheck->UserStatusCode ?? null),
                ],
                'IsChangePassword' => $user->loginCheck->IsChangePassword ?? null,
                'LastLogin' => $user->loginCheck->LastLoginTimeStamp ?? null,
                'LastLoginLocation' => $user->loginCheck->getLocationAsArray() ?? null,
                'LoginAttemptCounter' => $user->loginCheck->LastLoginAttemptCounter ?? 0,
                'CreatedAt' => $user->AtTimeStamp,
            ]
        ], 200);
    }

    /**
     * Create new user (Administrator)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'FullName' => 'required|string|max:100',
            'Email' => 'required|email|max:100|unique:user,Email',
            'Password' => 'required|string|min:6',
            'UTCCode' => 'nullable|string|max:6',
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
            $salt = Str::uuid()->toString();

            // Create user with IsAdministrator = 1
            $user = User::create([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'IsAdministrator' => true, // Force administrator
                'FullName' => $request->FullName,
                'Email' => $request->Email,
                'Password' => Hash::make($request->Password.$salt),
                'UTCCode' => $request->utc_code ?? '+07:00',
            ]);

            // Create LoginCheck with IsChangePassword = 1 and UserStatusCode = 11 (New)
            LoginCheck::create([
                'UserID' => $user->UserID,
                'UserStatusCode' => '11', // New
                'IsChangePassword' => true, // Must change password on first login
                'Salt' => $salt,
                'LastLoginTimeStamp' => null,
                'LastLoginLocationJSON' => null,
                'LastLoginAttemptCounter' => 0,
            ]);

            // Create audit log
            AuditLog::create([
                                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'User',
                'ReferenceRecordID' => $user->UserID,
                'Data' => json_encode([
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => true,
                ]),
                'Note' => 'New administrator user created'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'Status' => [
                        'Code' => '11',
                        'Label' => 'New',
                    ],
                    'IsChangePassword' => true,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'FullName' => 'nullable|string|max:100',
            // 'Email' => 'nullable|email|max:100|unique:user,Email,' . $id . ',UserID',
            // 'Password' => 'nullable|string|min:6',
            'UTCCode' => 'nullable|string|max:6',
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
            $userCheck = $user->loginCheck;

            $oldData = [
                'FullName' => $user->FullName,
                // 'Email' => $user->Email,
            ];

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('FullName')) {
                $updateData['FullName'] = $request->FullName;
            }

            // if ($request->has('Email')) {
            //     $updateData['Email'] = $request->email;
            // }

            // if ($request->has('Password')) {
            //     $Salt = Str::uuid()->toString();
            //     $updateData['Password'] = Hash::make($request->Password.$Salt);
                
                // If password is updated, set IsChangePassword to true
                // $user->loginCheck->update([
                    // 'IsChangePassword' => true, //IsChangePassword true is for login will change password before continue to the homepage.
            //         'Salt' => $Salt,
            //     ]);
            // }

            if ($request->has('UTCCode')) {
                $updateData['UTCCode'] = $request->UTCCode;
            }

            $user->update($updateData);

            // Create audit log
            AuditLog::create([
                                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'User',
                'ReferenceRecordID' => $user->UserID,
                'Data' => json_encode([
                    'Old' => $oldData,
                    'New' => [
                        'FullName' => $user->FullName,
                        'Esmail' => $user->Email,
                    ]
                ]),
                'Note' => 'User updated'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'IserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode' => $user->UTCCode,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Block/Unblock user
     */
    public function toggleBlock(Request $request, $id)
    {
        $user = User::with('loginCheck')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Prevent blocking self
        if ($id == $request->auth_user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot block yourself'
            ], 403);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $currentStatus = $user->loginCheck->UserStatusCode;

            // Toggle between Blocked (00) and Active (99)
            $newStatus = $currentStatus === '00' ? '99' : '00';
            $action = $newStatus === '00' ? 'blocked' : 'unblocked';

            $user->loginCheck->update([
                'UserStatusCode' => $newStatus,
                'LastLoginAttemptCounter' => 0, // Reset counter when unblocking
            ]);

            // Create audit log
            AuditLog::create([
                                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'User',
                'ReferenceRecordID' => $user->UserID,
                'Data' => json_encode([
                    'Action' => $action,
                    'OldStatus' => $currentStatus,
                    'NewStatus' => $newStatus,
                ]),
                'Note' => "User {$action}"
            ]);

            return response()->json([
                'success' => true,
                'message' => "User {$action} successfully",
                'data' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Status' => [
                        'Code' => $newStatus,
                        'Label' => $this->getStatusLabel($newStatus),
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle user block status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suspend/Unsuspend user
     */
    public function toggleSuspend(Request $request, $id)
    {
        $user = User::with('loginCheck')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Prevent suspending self
        if ($id == $request->auth_user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot suspend yourself'
            ], 403);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;
            $currentStatus = $user->loginCheck->UserStatusCode;

            // Toggle between Suspended (10) and Active (99)
            $newStatus = $currentStatus === '10' ? '99' : '10';
            $action = $newStatus === '10' ? 'suspended' : 'activated';

            $user->loginCheck->update([
                'UserStatusCode' => $newStatus,
            ]);

            // Create audit log
            AuditLog::create([
                                'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'User',
                'ReferenceRecordID' => $user->UserID,
                'Data' => json_encode([
                    'Action' => $action,
                    'OldStatus' => $currentStatus,
                    'NewStatus' => $newStatus,
                ]),
                'Note' => "User {$action}"
            ]);

            return response()->json([
                'success' => true,
                'message' => "User {$action} successfully",
                'data' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Status' => [
                        'Code' => $newStatus,
                        'Label' => $this->getStatusLabel($newStatus),
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle user suspend status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user (soft delete concept)
     * Note: Based on your schema, there's no IsDelete field in User table
     * This will actually delete the user record
     */
    // public function destroy(Request $request, $id)
    // {
    //     $user = User::find($id);

    //     if (!$user) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'User not found'
    //         ], 404);
    //     }

    //     // Prevent deleting self
    //     if ($id == $request->auth_user_id) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'You cannot delete yourself'
    //         ], 403);
    //     }

    //     try {
    //         $timestamp = Carbon::now()->timestamp;
    //         $authUserId = $request->auth_user_id;

    //         // Create audit log before deletion
    //         AuditLog::create([
    //             'AuditLogID'=>Carbon::now()->timestamp.random_numbersu(5),
    //             'AtTimeStamp' => $timestamp,
    //             'ByUserID' => $authUserId,
    //             'OperationCode' => 'D',
    //             'ReferenceTable' => 'User',
    //             'ReferenceRecordID' => $user->UserID,
    //             'Data' => json_encode([
    //                 'FullName' => $user->FullName,
    //                 'Email' => $user->Email,
    //             ]),
    //             'Note' => 'User deleted'
    //         ]);

    //         // Delete login check first (foreign key relation)
    //         LoginCheck::where('UserID', $id)->delete();

    //         // Delete user
    //         $user->delete();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'User deleted successfully'
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to delete user',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Helper: Get status label
     */
    private function getStatusLabel($code)
    {
        $statuses = [
            '00' => 'Blocked',
            '10' => 'Suspended',
            '11' => 'New',
            '99' => 'Active',
        ];

        return $statuses[$code] ?? 'Unknown';
    }
}