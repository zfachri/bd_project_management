<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePosition extends Model
{
    protected $table = 'EmployeePosition';
    protected $primaryKey = 'EmployeePositionID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'EmployeePositionID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'OrganizationID',
        'PositionID',
        'EmployeeID',
        'StartDate',
        'EndDate',
        'Note',
        'IsActive',
        'IsDelete',
    ];

    protected $casts = [
        'EmployeePositionID'=>'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'PositionID' => 'integer',
        'EmployeeID' => 'integer',
        'StartDate' => 'date',
        'EndDate' => 'date',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'EmployeeID', 'EmployeeID');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'PositionID', 'PositionID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)->where('IsDelete', false);
    }

    public function scopeCurrent($query)
    {
        return $query->where('IsActive', true)->whereNull('EndDate');
    }

    // Helper methods
    public function isCurrentPosition()
    {
        return $this->IsActive && is_null($this->EndDate);
    }
}
