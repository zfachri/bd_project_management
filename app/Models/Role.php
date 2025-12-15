<?php

/**
 * Model: Role
 * File: app/Models/Role.php
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'Role';
    protected $primaryKey = 'RoleID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'RoleID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'RoleName',
        'Description',
        'IsActive',
        'IsDelete',
    ];

    protected $casts = [
        'RoleID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function permissions()
    {
        return $this->hasMany(Permission::class, 'RoleID', 'RoleID')
            ->where('IsDelete', false);
    }

    public function employeeRoles()
    {
        return $this->hasMany(EmployeeRole::class, 'RoleID', 'RoleID');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)->where('IsDelete', false);
    }

    // Helper methods
    public function hasPermissionFor($moduleName, $action)
    {
        $permission = $this->permissions()
            ->whereHas('module', function ($query) use ($moduleName) {
                $query->where('ModuleName', $moduleName);
            })
            ->first();

        if (!$permission) {
            return false;
        }

        $actionColumn = 'Can' . ucfirst($action); // CanCreate, CanView, CanEdit, CanDelete
        return $permission->{$actionColumn} ?? false;
    }

    public function getPermissionsArray()
    {
        $permissions = [];
        
        foreach ($this->permissions as $permission) {
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
    }
}