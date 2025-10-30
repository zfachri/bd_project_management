<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginCheck extends Model
{
     protected $table = 'LoginCheck';
    protected $primaryKey = 'UserID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'UserID',
        'UserStatusCode',
        'IsChangePassword',
        'Salt',
        'LastLoginTimeStamp',
        'LastLoginLocationJSON',
        'LastLoginAttemptCounter',
    ];

    protected $casts = [
        'UserID' => 'integer',
        'IsChangePassword' => 'boolean',
        'LastLoginTimeStamp' => 'integer',
        'LastLoginAttemptCounter' => 'integer',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }

    // Helper method to get location as array
    public function getLocationAsArray()
    {
        return json_decode($this->LastLoginLocationJSON, true);
    }
}
