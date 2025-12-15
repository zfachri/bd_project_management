<?php

/**
 * Role Controller
 * File: app/Http/Controllers/Api/RoleController.php
 * 
 * Handles:
 * - CRUD Roles
 * - Assign/Update Permissions to Role
 * - Assign Role to Employee
 * - Get all modules
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Module;
use App\Models\Permission;
use App\Models\EmployeeRole;
use App\Models\Employee;
use App\Models\AuditLog;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RoleController extends Controller
{
    protected $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Get all roles with pagination
     * GET /api/roles
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $status = $request->input('status'); // active, inactive, all

        $query = Role::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('RoleName', 'like', "%{$search}%")
                    ->orWhere('Description', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive') {
            $query->where('IsActive', false);
        } else {
            $query->where('IsDelete', false);
        }

        $roles = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $roles
        ], 200);
    }

    /**
     * Get all roles without pagination
     * GET /api/roles/all
     */
    public function all(Request $request)
    {
        $roles = Role::active()->get();

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $roles
        ], 200);
    }

    /**
     * Get single role with permissions
     * GET /api/roles/{id}
     */
    public function show($id)
    {
        $role = Role::with(['permissions.module' => function ($query) {
            $query->where('IsActive', true)->orderBy('SortOrder', 'asc');
        }])->find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        // Format permissions grouped by module
        $permissionsByModule = [];
        foreach ($role->permissions as $permission) {
            if (!$permission->module) continue;

            $permissionsByModule[] = [
                'ModuleID' => $permission->ModuleID,
                'ModuleName' => $permission->module->ModuleName,
                'DisplayName' => $permission->module->DisplayName,
                'CanCreate' => $permission->CanCreate,
                'CanView' => $permission->CanView,
                'CanEdit' => $permission->CanEdit,
                'CanDelete' => $permission->CanDelete,
                'CanAccessSubordinates' => $permission->CanAccessSubordinates,
                'CanAccessParentOrg' => $permission->CanAccessParentOrg,
                'CanAccessChildOrg' => $permission->CanAccessChildOrg,
                'Scope' => $permission->Scope,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Role retrieved successfully',
            'data' => [
                'RoleID' => $role->RoleID,
                'RoleName' => $role->RoleName,
                'Description' => $role->Description,
                'IsActive' => $role->IsActive,
                'Permissions' => $permissionsByModule,
                'CreatedAt' => $role->AtTimeStamp,
            ]
        ], 200);
    }

    /**
     * Create new role
     * POST /api/roles
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'RoleName' => 'required|string|max:100|unique:Role,RoleName',
            'Description' => 'nullable|string|max:500',
            'Permissions' => 'nullable|array',
            'Permissions.*.ModuleID' => 'required|integer|exists:Module,ModuleID',
            'Permissions.*.CanCreate' => 'nullable|boolean',
            'Permissions.*.CanView' => 'nullable|boolean',
            'Permissions.*.CanEdit' => 'nullable|boolean',
            'Permissions.*.CanDelete' => 'nullable|boolean',
            'Permissions.*.CanAccessSubordinates' => 'nullable|boolean',
            'Permissions.*.CanAccessParentOrg' => 'nullable|boolean',
            'Permissions.*.CanAccessChildOrg' => 'nullable|boolean',
            'Permissions.*.Scope' => 'nullable|in:own,organization,position_tree,all',
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
            $roleId = $timestamp . random_numbersu(5);

            // Create role
            $role = Role::create([
                'RoleID' => $roleId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'RoleName' => $request->RoleName,
                'Description' => $request->Description,
                'IsActive' => true,
                'IsDelete' => false,
            ]);

            // Create permissions if provided
            if ($request->has('Permissions')) {
                foreach ($request->Permissions as $permissionData) {
                    $permissionId = Carbon::now()->timestamp . random_numbersu(5);

                    Permission::create([
                        'PermissionID' => $permissionId,
                        'AtTimeStamp' => $timestamp,
                        'ByUserID' => $authUserId,
                        'OperationCode' => 'I',
                        'RoleID' => $roleId,
                        'ModuleID' => $permissionData['ModuleID'],
                        'CanCreate' => $permissionData['CanCreate'] ?? false,
                        'CanView' => $permissionData['CanView'] ?? false,
                        'CanEdit' => $permissionData['CanEdit'] ?? false,
                        'CanDelete' => $permissionData['CanDelete'] ?? false,
                        'CanAccessSubordinates' => $permissionData['CanAccessSubordinates'] ?? false,
                        'CanAccessParentOrg' => $permissionData['CanAccessParentOrg'] ?? false,
                        'CanAccessChildOrg' => $permissionData['CanAccessChildOrg'] ?? false,
                        'Scope' => $permissionData['Scope'] ?? 'own',
                        'IsDelete' => false,
                    ]);
                }
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'Role',
                'ReferenceRecordID' => $roleId,
                'Data' => json_encode([
                    'RoleName' => $request->RoleName,
                    'PermissionsCount' => count($request->Permissions ?? []),
                ]),
                'Note' => 'Role created with permissions'
            ]);

            DB::commit();

            // Reload with permissions
            $role->load('permissions.module');

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update role
     * PUT /api/roles/{id}
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'RoleName' => 'nullable|string|max:100|unique:Role,RoleName,' . $id . ',RoleID',
            'Description' => 'nullable|string|max:500',
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

            $updateData = [
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
            ];

            if ($request->has('RoleName')) {
                $updateData['RoleName'] = $request->RoleName;
            }

            if ($request->has('Description')) {
                $updateData['Description'] = $request->Description;
            }

            if ($request->has('IsActive')) {
                $updateData['IsActive'] = $request->IsActive;
            }

            $role->update($updateData);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Role',
                'ReferenceRecordID' => $role->RoleID,
                'Data' => json_encode($updateData),
                'Note' => 'Role updated'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update role permissions
     * PUT /api/roles/{id}/permissions
     */
    public function updatePermissions(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'Permissions' => 'required|array',
            'Permissions.*.ModuleID' => 'required|integer|exists:Module,ModuleID',
            'Permissions.*.CanCreate' => 'nullable|boolean',
            'Permissions.*.CanView' => 'nullable|boolean',
            'Permissions.*.CanEdit' => 'nullable|boolean',
            'Permissions.*.CanDelete' => 'nullable|boolean',
            'Permissions.*.CanAccessSubordinates' => 'nullable|boolean',
            'Permissions.*.CanAccessParentOrg' => 'nullable|boolean',
            'Permissions.*.CanAccessChildOrg' => 'nullable|boolean',
            'Permissions.*.Scope' => 'nullable|in:own,organization,position_tree,all',
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

            // Soft delete existing permissions
            Permission::where('RoleID', $id)->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsDelete' => true
            ]);

            // Create new permissions
            foreach ($request->Permissions as $permissionData) {
                $permissionId = Carbon::now()->timestamp . random_numbersu(5);

                Permission::create([
                    'PermissionID' => $permissionId,
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'I',
                    'RoleID' => $id,
                    'ModuleID' => $permissionData['ModuleID'],
                    'CanCreate' => $permissionData['CanCreate'] ?? false,
                    'CanView' => $permissionData['CanView'] ?? false,
                    'CanEdit' => $permissionData['CanEdit'] ?? false,
                    'CanDelete' => $permissionData['CanDelete'] ?? false,
                    'CanAccessSubordinates' => $permissionData['CanAccessSubordinates'] ?? false,
                    'CanAccessParentOrg' => $permissionData['CanAccessParentOrg'] ?? false,
                    'CanAccessChildOrg' => $permissionData['CanAccessChildOrg'] ?? false,
                    'Scope' => $permissionData['Scope'] ?? 'own',
                    'IsDelete' => false,
                ]);
            }

            // Clear cache for all employees with this role
            $employeeRoles = EmployeeRole::where('RoleID', $id)->active()->get();
            foreach ($employeeRoles as $empRole) {
                $this->permissionService->clearCache($empRole->EmployeeID);
            }

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'ReferenceTable' => 'Role',
                'ReferenceRecordID' => $id,
                'Data' => json_encode([
                    'PermissionsCount' => count($request->Permissions),
                ]),
                'Note' => 'Role permissions updated'
            ]);

            DB::commit();

            // Reload with permissions
            $role->load('permissions.module');

            return response()->json([
                'success' => true,
                'message' => 'Permissions updated successfully',
                'data' => $role
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete role (soft delete)
     * DELETE /api/roles/{id}
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        // Check if role is assigned to any employee
        $assignedCount = EmployeeRole::where('RoleID', $id)->active()->count();

        if ($assignedCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete role. It is currently assigned to {$assignedCount} employee(s)."
            ], 400);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            // Soft delete role
            $role->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'IsDelete' => true,
                'IsActive' => false,
            ]);

            // Soft delete permissions
            Permission::where('RoleID', $id)->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'IsDelete' => true
            ]);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'Role',
                'ReferenceRecordID' => $id,
                'Data' => json_encode(['RoleName' => $role->RoleName]),
                'Note' => 'Role deleted (soft delete)'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign role to employee
     * POST /api/roles/assign
     */
    public function assignToEmployee(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'EmployeeID' => 'required|integer|exists:Employee,EmployeeID',
            'RoleID' => 'required|integer|exists:Role,RoleID',
            'OrganizationID' => 'nullable|integer|exists:Organization,OrganizationID',
            'PositionID' => 'nullable|integer|exists:Position,PositionID',
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

            // Check if employee already has active role
            $existingRole = EmployeeRole::where('EmployeeID', $request->EmployeeID)
                ->active()
                ->first();

            if ($existingRole) {
                // Deactivate old role
                $existingRole->update([
                    'AtTimeStamp' => $timestamp,
                    'ByUserID' => $authUserId,
                    'OperationCode' => 'U',
                    'IsActive' => false,
                ]);
            }

            $employeeRoleId = $timestamp . random_numbersu(5);

            // Create new role assignment
            $employeeRole = EmployeeRole::create([
                'EmployeeRoleID' => $employeeRoleId,
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'EmployeeID' => $request->EmployeeID,
                'RoleID' => $request->RoleID,
                'OrganizationID' => $request->OrganizationID,
                'PositionID' => $request->PositionID,
                'IsActive' => true,
                'IsDelete' => false,
                'AssignedAt' => $timestamp,
            ]);

            // Clear permission cache
            $this->permissionService->clearCache($request->EmployeeID);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'I',
                'ReferenceTable' => 'EmployeeRole',
                'ReferenceRecordID' => $employeeRoleId,
                'Data' => json_encode([
                    'EmployeeID' => $request->EmployeeID,
                    'RoleID' => $request->RoleID,
                ]),
                'Note' => 'Role assigned to employee'
            ]);

            DB::commit();

            // Reload with relationships
            $employeeRole->load(['employee.user', 'role']);

            return response()->json([
                'success' => true,
                'message' => 'Role assigned successfully',
                'data' => $employeeRole
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove role from employee
     * DELETE /api/roles/unassign/{employeeId}
     */
    public function unassignFromEmployee(Request $request, $employeeId)
    {
        $employeeRole = EmployeeRole::where('EmployeeID', $employeeId)
            ->active()
            ->first();

        if (!$employeeRole) {
            return response()->json([
                'success' => false,
                'message' => 'Employee has no active role assigned'
            ], 404);
        }

        try {
            $timestamp = Carbon::now()->timestamp;
            $authUserId = $request->auth_user_id;

            $employeeRole->update([
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'U',
                'IsActive' => false,
                'IsDelete' => true,
            ]);

            // Clear permission cache
            $this->permissionService->clearCache($employeeId);

            // Create audit log
            AuditLog::create([
                'AuditLogID' => Carbon::now()->timestamp . random_numbersu(5),
                'AtTimeStamp' => $timestamp,
                'ByUserID' => $authUserId,
                'OperationCode' => 'D',
                'ReferenceTable' => 'EmployeeRole',
                'ReferenceRecordID' => $employeeRole->EmployeeRoleID,
                'Data' => json_encode([
                    'EmployeeID' => $employeeId,
                    'RoleID' => $employeeRole->RoleID,
                ]),
                'Note' => 'Role unassigned from employee'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role unassigned successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all modules (for permission setup)
     * GET /api/roles/modules
     */
    public function getModules()
    {
        $modules = Module::active()->get();

        return response()->json([
            'success' => true,
            'message' => 'Modules retrieved successfully',
            'data' => $modules
        ], 200);
    }

    /**
     * Get employee's current role and permissions
     * GET /api/roles/employee/{employeeId}
     */
    public function getEmployeeRole($employeeId)
    {
        $employee = Employee::with('user')->find($employeeId);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found'
            ], 404);
        }

        $permissionDetails = $this->permissionService->getPermissionDetails($employeeId);

        return response()->json([
            'success' => true,
            'message' => 'Employee role retrieved successfully',
            'data' => $permissionDetails
        ], 200);
    }
}