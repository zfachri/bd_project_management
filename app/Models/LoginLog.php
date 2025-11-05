<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    protected $table = 'LoginLog';
    protected $primaryKey = 'LoginLogID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'LoginLogID',
        'UserID',
        'IsSuccessful',
        'LoginTimeStamp',
        'LoginLocationJSON',
    ];

    protected $casts = [
        'LoginLogID'=>'integer',
        'UserID' => 'integer',
        'IsSuccessful' => 'boolean',
        'LoginTimeStamp' => 'integer',
        'LoginLocationJSON' => 'string'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }

    // Helper method to get location as array
    public function getLocationAsArray()
    {
        return json_decode($this->LoginLocationJSON, true);
    }
}
