<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
     protected $table = 'Employee';
    protected $primaryKey = 'EmployeeID';
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'OrganizationID',
        'GenderCode',
        'DateOfBirth',
        'JoinDate',
        'ResignDate',
        'Note',
        'IsDelete',
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'DateOfBirth' => 'date',
        'JoinDate' => 'date',
        'ResignDate' => 'date',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'EmployeeID', 'UserID');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    // Helper methods
    public function isMale()
    {
        return $this->GenderCode === 'M';
    }

    public function isFemale()
    {
        return $this->GenderCode === 'F';
    }

    public function isResigned()
    {
        return !is_null($this->ResignDate);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsDelete', false)->whereNull('ResignDate');
    }
}
