<?php

/**
 * Permission Service
 * File: app/Services/PermissionService.php
 * 
 * Handles all permission checking logic including:
 * - Basic CRUD permissions
 * - Hierarchical access (subordinates, parent/child org)
 * - Scope-based data access
 */

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Module;
use App\Models\Position;
use App\Models\Organization;
use App\Models\EmployeePosition;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    /**
     * Check if employee has permission for action on module
     * 
     * @param int $employeeId
     * @param string $moduleName
     * @param string $action (create, view, edit, delete)
     * @return bool
     */
    public function hasPermission($employeeId, $moduleName, $action)
    {
        $user = User::with('employee')->find($employeeId);
        // Check if user is administrator
        $employee = Employee::with('user')->find($employeeId);
        
        if (!$user) {
            return false;
        }

        // Administrator has full access
        if ($user->IsAdministrator) {
            return true;
        }

        // Get employee's active role with permissions
        $employeeRole = EmployeeRole::with(['role.permissions.module'])
            ->where('EmployeeID', $employeeId)
            ->active()
            ->first();

        if (!$employeeRole || !$employeeRole->role) {
            return false;
        }

        // Find permission for this module
        $permission = $employeeRole->role->permissions()
            ->whereHas('module', function ($query) use ($moduleName) {
                $query->where('ModuleName', $moduleName)
                    ->where('IsActive', true);
            })
            ->active()
            ->first();

        if (!$permission) {
            return false;
        }

        // Check specific action
        $actionColumn = 'Can' . ucfirst($action);
        return $permission->{$actionColumn} ?? false;
    }

    /**
     * Get all permissions for employee in flat array format
     * 
     * @param int $employeeId
     * @return array ["ModuleName.action", ...]
     */
    public function getEmployeePermissions($employeeId)
    {
        // Cache key
        $cacheKey = "employee_permissions_{$employeeId}";

        // Try to get from cache (5 minutes)
        return Cache::remember($cacheKey, 300, function () use ($employeeId) {
            $permissions = [];

            // Check if user is administrator
            $employee = Employee::with('user')->find($employeeId);
            
            if (!$employee || !$employee->user) {
                return $permissions;
            }

            // Administrator has all permissions
            if ($employee->user->IsAdministrator) {
                return $this->getAllPermissions();
            }

            // Get employee's role permissions
            $employeeRole = EmployeeRole::with(['role.permissions.module'])
                ->where('EmployeeID', $employeeId)
                ->active()
                ->first();

            if (!$employeeRole || !$employeeRole->role) {
                return $permissions;
            }

            foreach ($employeeRole->role->permissions as $permission) {
                if (!$permission->module || !$permission->module->IsActive) {
                    continue;
                }

                $moduleName = $permission->module->ModuleName;

                if ($permission->CanCreate) {
                    $permissions[] = "{$moduleName}.create";
                }
                if ($permission->CanView) {
                    $permissions[] = "{$moduleName}.view";
                }
                if ($permission->CanEdit) {
                    $permissions[] = "{$moduleName}.edit";
                }
                if ($permission->CanDelete) {
                    $permissions[] = "{$moduleName}.delete";
                }
            }

            return $permissions;
        });
    }

    /**
     * Get all possible permissions (for administrator)
     * 
     * @return array
     */
    private function getAllPermissions()
    {
        $permissions = [];
        $modules = Module::active()->get();

        foreach ($modules as $module) {
            $permissions[] = "{$module->ModuleName}.create";
            $permissions[] = "{$module->ModuleName}.view";
            $permissions[] = "{$module->ModuleName}.edit";
            $permissions[] = "{$module->ModuleName}.delete";
        }

        return $permissions;
    }

    /**
     * Check if employee can access data based on scope and hierarchy
     * 
     * @param int $employeeId
     * @param string $moduleName
     * @param int|null $targetEmployeeId
     * @param int|null $targetOrganizationId
     * @return bool
     */
    public function canAccessData($employeeId, $moduleName, $targetEmployeeId = null, $targetOrganizationId = null)
    {
        $employee = Employee::with(['user', 'currentPosition.position.organization'])->find($employeeId);
        
        if (!$employee || !$employee->user) {
            return false;
        }

        // Administrator has full access
        if ($employee->user->IsAdministrator) {
            return true;
        }

        // Get permission for module
        $permission = $this->getPermission($employeeId, $moduleName);

        if (!$permission) {
            return false;
        }

        // Check scope
        switch ($permission->Scope) {
            case 'all':
                return true;

            case 'own':
                // Can only access own data
                return $targetEmployeeId === $employeeId;

            case 'organization':
                // Can access data within same organization
                if (!$employee->currentPosition || !$employee->currentPosition->position) {
                    return false;
                }
                
                $myOrgId = $employee->currentPosition->position->OrganizationID;
                
                // Check if target is in same organization
                if ($targetOrganizationId) {
                    return $targetOrganizationId === $myOrgId;
                }
                
                if ($targetEmployeeId) {
                    $targetEmployee = Employee::with('currentPosition.position')->find($targetEmployeeId);
                    return $targetEmployee && 
                           $targetEmployee->currentPosition && 
                           $targetEmployee->currentPosition->position &&
                           $targetEmployee->currentPosition->position->OrganizationID === $myOrgId;
                }
                
                return false;

            case 'position_tree':
                // Can access based on position hierarchy
                return $this->canAccessByPositionHierarchy(
                    $employee,
                    $permission,
                    $targetEmployeeId,
                    $targetOrganizationId
                );

            default:
                return false;
        }
    }

    /**
     * Check access based on position hierarchy
     * 
     * @param Employee $employee
     * @param Permission $permission
     * @param int|null $targetEmployeeId
     * @param int|null $targetOrganizationId
     * @return bool
     */
    private function canAccessByPositionHierarchy($employee, $permission, $targetEmployeeId, $targetOrganizationId)
    {
        $currentPosition = $employee->currentPosition;
        
        if (!$currentPosition || !$currentPosition->position) {
            return false;
        }

        $myPosition = $currentPosition->position;
        $myOrgId = $myPosition->OrganizationID;

        // Own data always accessible
        if ($targetEmployeeId === $employee->EmployeeID) {
            return true;
        }

        // Get target employee's position
        if ($targetEmployeeId) {
            $targetEmployee = Employee::with('currentPosition.position')->find($targetEmployeeId);
            
            if (!$targetEmployee || !$targetEmployee->currentPosition || !$targetEmployee->currentPosition->position) {
                return false;
            }

            $targetPosition = $targetEmployee->currentPosition->position;
            $targetOrgId = $targetPosition->OrganizationID;

            // Check organization access first
            if (!$this->canAccessOrganization($myOrgId, $targetOrgId, $permission)) {
                return false;
            }

            // Check position hierarchy
            if ($permission->CanAccessSubordinates) {
                // Check if target is subordinate
                if ($this->isSubordinate($myPosition, $targetPosition, $myOrgId)) {
                    return true;
                }
            }

            // Check if can access parent's data
            if ($permission->CanAccessParentOrg) {
                if ($this->isSuperior($myPosition, $targetPosition, $myOrgId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if employee can access organization
     * 
     * @param int $myOrgId
     * @param int $targetOrgId
     * @param Permission $permission
     * @return bool
     */
    private function canAccessOrganization($myOrgId, $targetOrgId, $permission)
    {
        // Same organization
        if ($myOrgId === $targetOrgId) {
            return true;
        }

        // Check parent organization access
        if ($permission->CanAccessParentOrg) {
            if ($this->isParentOrganization($myOrgId, $targetOrgId)) {
                return true;
            }
        }

        // Check child organization access
        if ($permission->CanAccessChildOrg) {
            if ($this->isChildOrganization($myOrgId, $targetOrgId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if target position is subordinate of my position
     * 
     * @param Position $myPosition
     * @param Position $targetPosition
     * @param int $organizationId
     * @return bool
     */
    private function isSubordinate($myPosition, $targetPosition, $organizationId)
    {
        // Check if target's parent chain includes my position
        $currentPos = $targetPosition;
        $visited = [];

        while ($currentPos && !in_array($currentPos->PositionID, $visited)) {
            $visited[] = $currentPos->PositionID;

            // Found myself in the chain = target is my subordinate
            if ($currentPos->ParentPositionID === $myPosition->PositionID) {
                return true;
            }

            // Stop if parent is itself (root)
            if ($currentPos->ParentPositionID === $currentPos->PositionID) {
                break;
            }

            $currentPos = Position::where('PositionID', $currentPos->ParentPositionID)
                ->where('OrganizationID', $organizationId)
                ->first();
        }

        return false;
    }

    /**
     * Check if target position is superior of my position
     * 
     * @param Position $myPosition
     * @param Position $targetPosition
     * @param int $organizationId
     * @return bool
     */
    private function isSuperior($myPosition, $targetPosition, $organizationId)
    {
        // Check if my parent chain includes target position
        $currentPos = $myPosition;
        $visited = [];

        while ($currentPos && !in_array($currentPos->PositionID, $visited)) {
            $visited[] = $currentPos->PositionID;

            // Found target in my parent chain = target is my boss
            if ($currentPos->ParentPositionID === $targetPosition->PositionID) {
                return true;
            }

            // Stop if parent is itself (root)
            if ($currentPos->ParentPositionID === $currentPos->PositionID) {
                break;
            }

            $currentPos = Position::where('PositionID', $currentPos->ParentPositionID)
                ->where('OrganizationID', $organizationId)
                ->first();
        }

        return false;
    }

    /**
     * Check if target organization is parent of my organization
     * 
     * @param int $myOrgId
     * @param int $targetOrgId
     * @return bool
     */
    private function isParentOrganization($myOrgId, $targetOrgId)
    {
        $myOrg = Organization::find($myOrgId);
        
        if (!$myOrg) {
            return false;
        }

        $visited = [];
        $currentOrg = $myOrg;

        while ($currentOrg && !in_array($currentOrg->OrganizationID, $visited)) {
            $visited[] = $currentOrg->OrganizationID;

            if ($currentOrg->ParentOrganizationID === $targetOrgId) {
                return true;
            }

            // Stop if parent is itself (root)
            if ($currentOrg->ParentOrganizationID === $currentOrg->OrganizationID) {
                break;
            }

            $currentOrg = Organization::find($currentOrg->ParentOrganizationID);
        }

        return false;
    }

    /**
     * Check if target organization is child of my organization
     * 
     * @param int $myOrgId
     * @param int $targetOrgId
     * @return bool
     */
    private function isChildOrganization($myOrgId, $targetOrgId)
    {
        $targetOrg = Organization::find($targetOrgId);
        
        if (!$targetOrg) {
            return false;
        }

        $visited = [];
        $currentOrg = $targetOrg;

        while ($currentOrg && !in_array($currentOrg->OrganizationID, $visited)) {
            $visited[] = $currentOrg->OrganizationID;

            if ($currentOrg->ParentOrganizationID === $myOrgId) {
                return true;
            }

            // Stop if parent is itself (root)
            if ($currentOrg->ParentOrganizationID === $currentOrg->OrganizationID) {
                break;
            }

            $currentOrg = Organization::find($currentOrg->ParentOrganizationID);
        }

        return false;
    }

    /**
     * Get permission object for employee and module
     * 
     * @param int $employeeId
     * @param string $moduleName
     * @return Permission|null
     */
    private function getPermission($employeeId, $moduleName)
    {
        $employeeRole = EmployeeRole::with(['role.permissions.module'])
            ->where('EmployeeID', $employeeId)
            ->active()
            ->first();

        if (!$employeeRole || !$employeeRole->role) {
            return null;
        }

        return $employeeRole->role->permissions()
            ->whereHas('module', function ($query) use ($moduleName) {
                $query->where('ModuleName', $moduleName)
                    ->where('IsActive', true);
            })
            ->active()
            ->first();
    }

    /**
     * Clear permission cache for employee
     * 
     * @param int $employeeId
     * @return void
     */
    public function clearCache($employeeId)
    {
        Cache::forget("employee_permissions_{$employeeId}");
    }

    /**
     * Get permission details for employee (for debugging/display)
     * 
     * @param int $employeeId
     * @return array
     */
    public function getPermissionDetails($employeeId)
    {
        $employee = Employee::with(['user', 'currentPosition.position'])->find($employeeId);
        
        if (!$employee || !$employee->user) {
            return [
                'has_role' => false,
                'is_administrator' => false,
                'permissions' => []
            ];
        }

        if ($employee->user->IsAdministrator) {
            return [
                'has_role' => true,
                'is_administrator' => true,
                'role_name' => 'Administrator',
                'permissions' => $this->getAllPermissions()
            ];
        }

        $employeeRole = EmployeeRole::with(['role.permissions.module'])
            ->where('EmployeeID', $employeeId)
            ->active()
            ->first();

        if (!$employeeRole || !$employeeRole->role) {
            return [
                'has_role' => false,
                'is_administrator' => false,
                'permissions' => []
            ];
        }

        return [
            'has_role' => true,
            'is_administrator' => false,
            'role_id' => $employeeRole->RoleID,
            'role_name' => $employeeRole->role->RoleName,
            'role_description' => $employeeRole->role->Description,
            'permissions' => $this->getEmployeePermissions($employeeId)
        ];
    }
}