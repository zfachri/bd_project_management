<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OTP extends Model
{
    protected $table = 'OTP';
    protected $primaryKey = 'OTPID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'AtTimeStamp',
        'ExpiryTimeStamp',
        'UserID',
        'OTPCategoryCode',
        'OTP',
        'IsUsed',
        'OTPID'
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ExpiryTimeStamp' => 'integer',
        'UserID' => 'integer',
        'OTPID' => 'integer',
        'IsUsed' => 'boolean'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }

    // Helper methods
    public function isExpired()
    {
        return $this->ExpiryTimeStamp < now()->timestamp;
    }

    public function isSMS()
    {
        return $this->OTPCategoryCode === '01';
    }

    public function isEmail()
    {
        return $this->OTPCategoryCode === '02';
    }
}
