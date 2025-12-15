<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeRole extends Model
{
    protected $table = 'EmployeeRole';
    protected $primaryKey = 'EmployeeRoleID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'EmployeeRoleID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'EmployeeID',
        'RoleID',
        'OrganizationID',
        'PositionID',
        'IsActive',
        'IsDelete',
        'AssignedAt',
    ];

    protected $casts = [
        'EmployeeRoleID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'EmployeeID' => 'integer',
        'RoleID' => 'integer',
        'OrganizationID' => 'integer',
        'PositionID' => 'integer',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
        'AssignedAt' => 'integer',
    ];

    // Relationships
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'EmployeeID', 'EmployeeID');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'RoleID', 'RoleID');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'PositionID', 'PositionID');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)->where('IsDelete', false);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('EmployeeID', $employeeId);
    }

    // Helper methods
    public function isGlobal()
    {
        return is_null($this->OrganizationID) && is_null($this->PositionID);
    }

    public function isScopedToOrganization()
    {
        return !is_null($this->OrganizationID);
    }

    public function isScopedToPosition()
    {
        return !is_null($this->PositionID);
    }
}