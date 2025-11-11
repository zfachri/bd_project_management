<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'User';
    protected $primaryKey = 'UserID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'UserID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'IsAdministrator',
        'FullName',
        'Email',
        'Password',
        'UTCCode',
    ];

    protected $hidden = [
        'Password',
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'IsAdministrator' => 'boolean',
    ];

    // Override password column name
    public function getAuthPassword()
    {
        return $this->Password;
    }

    // Relationships
    public function loginCheck()
    {
        return $this->hasOne(LoginCheck::class, 'UserID', 'UserID');
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class, 'UserID', 'UserID');
    }

    public function otps()
    {
        return $this->hasMany(OTP::class, 'UserID', 'UserID');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'ByUserID', 'UserID');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'EmployeeID', 'UserID');
    }

    // Helper methods
    public function isActive()
    {
        return $this->loginCheck && $this->loginCheck->UserStatusCode === '99';
    }

    public function isNew()
    {
        return $this->loginCheck && $this->loginCheck->UserStatusCode === '11';
    }

    public function isSuspended()
    {
        return $this->loginCheck && $this->loginCheck->UserStatusCode === '10';
    }

    public function isBlocked()
    {
        return $this->loginCheck && $this->loginCheck->UserStatusCode === '00';
    }
}
