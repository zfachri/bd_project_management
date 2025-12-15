<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'Permission';
    protected $primaryKey = 'PermissionID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'PermissionID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'RoleID',
        'ModuleID',
        'CanCreate',
        'CanView',
        'CanEdit',
        'CanDelete',
        'CanAccessSubordinates',
        'CanAccessParentOrg',
        'CanAccessChildOrg',
        'Scope',
        'IsDelete',
    ];

    protected $casts = [
        'PermissionID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'RoleID' => 'integer',
        'ModuleID' => 'integer',
        'CanCreate' => 'boolean',
        'CanView' => 'boolean',
        'CanEdit' => 'boolean',
        'CanDelete' => 'boolean',
        'CanAccessSubordinates' => 'boolean',
        'CanAccessParentOrg' => 'boolean',
        'CanAccessChildOrg' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function role()
    {
        return $this->belongsTo(Role::class, 'RoleID', 'RoleID');
    }

    public function module()
    {
        return $this->belongsTo(Module::class, 'ModuleID', 'ModuleID');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

     // Helper methods
    public function can($action)
    {
        $actionColumn = 'Can' . ucfirst($action);
        return $this->{$actionColumn} ?? false;
    }

    public function getPermissionsArray()
    {
        $permissions = [];
        $moduleName = $this->module->ModuleName;
        
        if ($this->CanCreate) $permissions[] = "{$moduleName}.create";
        if ($this->CanView) $permissions[] = "{$moduleName}.view";
        if ($this->CanEdit) $permissions[] = "{$moduleName}.edit";
        if ($this->CanDelete) $permissions[] = "{$moduleName}.delete";
        
        return $permissions;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsDelete', false);
    }

    public function scopeForModule($query, $moduleName)
    {
        return $query->whereHas('module', function ($q) use ($moduleName) {
            $q->where('ModuleName', $moduleName);
        });
    }
}