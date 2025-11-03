<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    protected $table = 'RefreshToken';
    protected $primaryKey = 'RefreshTokenID';
    public $timestamps = false;

    protected $fillable = [
        'UserID',
        'Token',
        'ExpiresAt',
        'IsUsed',
        'UsedAt',
        'CreatedAt',
    ];

    protected $casts = [
        'UserID' => 'integer',
        'ExpiresAt' => 'integer',
        'IsUsed' => 'boolean',
        'UsedAt' => 'integer',
        'CreatedAt' => 'integer',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }

    // Scopes
    public function scopeValid($query)
    {
        return $query->where('IsUsed', false)
            ->where('ExpiresAt', '>', now()->timestamp);
    }

    // Helper methods
    public function isExpired()
    {
        return $this->ExpiresAt < now()->timestamp;
    }

    public function isValid()
    {
        return !$this->IsUsed && !$this->isExpired();
    }

    public function markAsUsed()
    {
        $this->update([
            'IsUsed' => true,
            'UsedAt' => now()->timestamp,
        ]);
    }
}