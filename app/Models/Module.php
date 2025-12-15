<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $table = 'Module';
    protected $primaryKey = 'ModuleID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ModuleID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ModuleName',
        'DisplayName',
        'Description',
        'SortOrder',
        'IsActive',
        'IsDelete',
    ];

    protected $casts = [
        'ModuleID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'SortOrder' => 'integer',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function permissions()
    {
        return $this->hasMany(Permission::class, 'ModuleID', 'ModuleID');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)
            ->where('IsDelete', false)
            ->orderBy('SortOrder', 'asc');
    }

    // Static method untuk get module by name
    public static function getByName($moduleName)
    {
        return static::where('ModuleName', $moduleName)
            ->where('IsActive', true)
            ->where('IsDelete', false)
            ->first();
    }
}

