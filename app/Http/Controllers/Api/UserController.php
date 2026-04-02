<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoginCheck;
use App\Models\AuditLog;
use App\Models\SystemReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $query = User::with([
            'loginCheck',
            'employee.organization',
            'employee.currentPosition.position',
        ]);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('FullName', 'like', "%{$search}%")
                  ->orWhere('Email', 'like', "%{$search}%")
                  ->orWhere('UserID', $search);
            });
        }

        $users = $query->paginate($perPage);

        $users->getCollection()->transform(function($user) {
            $employeeInfo = $this->buildEmployeeInfo($user);

            return [
                'UserID' => $user->UserID,
                'FullName' => $user->FullName,
                'Email' => $user->Email,
                'IsAdministrator' => $user->IsAdministrator,
                'UTCCode' => $user->UTCCode,
                'IsEmployee' => $employeeInfo['IsEmployee'],
                'Employee' => $employeeInfo['Employee'],
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
        $user = User::with([
            'loginCheck',
            'employee.organization',
            'employee.currentPosition.position',
        ])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $employeeInfo = $this->buildEmployeeInfo($user);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => [
                'UserID' => $user->UserID,
                'FullName' => $user->FullName,
                'Email' => $user->Email,
                'IsAdministrator' => $user->IsAdministrator,
                'UTCCode' => $user->UTCCode,
                'IsEmployee' => $employeeInfo['IsEmployee'],
                'Employee' => $employeeInfo['Employee'],
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
            'Email' => 'required|email|max:100|unique:User,Email',
            'Password' => 'nullable|string|min:6',
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
            $defaultPassword = random_string(6).$salt;
            $sendPassword = substr($defaultPassword, 0, 6);
            $user = User::create([
                'UserID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'IsAdministrator' => true, // Force administrator
                'FullName' => $request->FullName,
                'Email' => $request->Email,
                'Password' => Hash::make($defaultPassword),
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

            $this->sendNewUserCredentialEmail($user, $sendPassword);

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
        $user = User::with(['loginCheck'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        if ($user->loginCheck && $user->loginCheck->UserStatusCode === '00') {
            return response()->json([
                'success' => false,
                'message' => 'Blocked user cannot be updated'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'FullName' => 'nullable|string|max:100',
            'Email' => 'nullable|email|max:100|unique:User,Email,' . $id . ',UserID',
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

        DB::beginTransaction();

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $oldData = [
                'FullName' => $user->FullName,
                'Email' => $user->Email,
                'UTCCode' => $user->UTCCode,
            ];

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            $isEmailChanged = false;

            if ($request->has('FullName')) {
                $updateData['FullName'] = $request->FullName;
            }

            if ($request->has('Email')) {
                $updateData['Email'] = $request->Email;
                $isEmailChanged = $request->Email !== $user->Email;
            }

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

            if ($isEmailChanged && $user->loginCheck) {
                $user->loginCheck->update([
                    'UserStatusCode' => '11',
                ]);
            }

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
                        'Email' => $user->Email,
                        'UTCCode' => $user->UTCCode,
                    ]
                ]),
                'Note' => 'User updated'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'UserID' => $user->UserID,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'IsAdministrator' => $user->IsAdministrator,
                    'UTCCode' => $user->UTCCode,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

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
     * Get user audit logs
     * Admin user: return only User logs
     * Non-admin user: return User logs and Employee logs
     */
    public function getAuditLogs(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $userLogs = AuditLog::where('ReferenceTable', 'User')
            ->where('ReferenceRecordID', $id)
            ->orderBy('AtTimeStamp', 'desc')
            ->get();

        if ((bool) $user->IsAdministrator) {
            return response()->json([
                'success' => true,
                'message' => 'User audit logs retrieved successfully',
                'data' => [
                    'UserID' => (int) $id,
                    'IsAdministrator' => true,
                    'UserLogs' => $userLogs,
                ]
            ], 200);
        }

        $employeeLogs = AuditLog::where('ReferenceTable', 'Employee')
            ->where('ReferenceRecordID', $id)
            ->orderBy('AtTimeStamp', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'User audit logs retrieved successfully',
            'data' => [
                'UserID' => (int) $id,
                'IsAdministrator' => false,
                'UserLogs' => $userLogs,
                'EmployeeLogs' => $employeeLogs,
            ]
        ], 200);
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

    private function sendNewUserCredentialEmail(User $user, string $plainPassword): void
    {
        if (empty($user->Email)) {
            return;
        }

        $subject = 'Informasi Akun Pengguna Baru';
        $siteName = $this->getSystemReferenceValue('System', 'Site Name', 'https://www.valista.co.id/bd-app/login');
        $fallbackBody = "Dengan hormat,\n\n"
            . "Akun Anda telah berhasil dibuat.\n"
            . "Username: {$user->UserID}\n"
            . "Email: {$user->Email}\n"
            . "Password: {$plainPassword}\n"
            . "Link Login: {$siteName}\n\n"
            . "Mohon segera login dan ubah password Anda.\n\n"
            . "Terima kasih.";

        $template = $this->getSystemReferenceValue('User', 'Add User');
        if (!empty($template)) {
            $html = strtr($template, [
                '{{app_name}}' => (string) config('app.name', 'System'),
                '{{year}}' => (string) date('Y'),
                '{{full_name}}' => (string) ($user->FullName ?? '-'),
                '{{user_id}}' => (string) $user->UserID,
                '{{email}}' => (string) $user->Email,
                '{{password}}' => $plainPassword,
                '{{site_name}}' => $siteName,
            ]);

            try {
                Mail::html($html, function ($message) use ($user, $subject) {
                    $message->to($user->Email)->subject($subject);
                });
                return;
            } catch (\Throwable $e) {
                Log::warning('Failed to send new user HTML email', [
                    'email' => $user->Email,
                    'user_id' => $user->UserID,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            Mail::raw($fallbackBody, function ($message) use ($user, $subject) {
                $message->to($user->Email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to send new user credential email', [
                'email' => $user->Email,
                'user_id' => $user->UserID,
                'error' => $e->getMessage(),
            ]);
        }
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
            Log::warning('Failed to resolve system reference value', [
                'reference_name' => $referenceName,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    private function buildEmployeeInfo(User $user): array
    {
        $employee = $user->employee;
        $isEmployee = !is_null($employee);

        if (!$isEmployee) {
            return [
                'IsEmployee' => false,
                'Employee' => null,
            ];
        }

        $organization = $employee->organization;
        $currentPosition = $employee->currentPosition;
        $position = $currentPosition?->position;

        return [
            'IsEmployee' => true,
            'Employee' => [
                'EmployeeID' => $employee->EmployeeID,
                'IsActive' => !$employee->IsDelete,
                'Organization' => [
                    'OrganizationID' => $organization?->OrganizationID,
                    'OrganizationName' => $organization?->OrganizationName,
                    'IsActive' => $organization
                        ? ((bool) $organization->IsActive && !$organization->IsDelete)
                        : null,
                ],
                'Position' => [
                    'PositionID' => $position?->PositionID,
                    'PositionName' => $position?->PositionName,
                    'Status' => $currentPosition
                        ? ($currentPosition->IsActive ? 'Active' : 'Inactive')
                        : null,
                ],
            ],
        ];
    }
}
