<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'Organization';
    protected $primaryKey = 'OrganizationID';
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ParentOrganizationID',
        'LevelNo',
        'IsChild',
        'OrganizationName',
        'IsActive',
        'IsDelete',
        'OrganizationID'
    ];

    protected $casts = [
        'OrganizationID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'ParentOrganizationID' => 'integer',
        'LevelNo' => 'integer',
        'IsChild' => 'boolean',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    public function parent()
    {
        return $this->belongsTo(Organization::class, 'ParentOrganizationID', 'OrganizationID');
    }

    public function children()
    {
        return $this->hasMany(Organization::class, 'ParentOrganizationID', 'OrganizationID');
    }

    public function positions()
    {
        return $this->hasMany(Position::class, 'OrganizationID', 'OrganizationID');
    }

    public function jobDescriptions()
    {
        return $this->hasMany(JobDescription::class, 'OrganizationID', 'OrganizationID');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'OrganizationID', 'OrganizationID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)->where('IsDelete', false);
    }
}
