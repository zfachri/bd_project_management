<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PositionLevel extends Model
{
    protected $table = 'PositionLevel';
    protected $primaryKey = 'PositionLevelID';
    public $timestamps = false;

    protected $fillable = [
        'PositionLevelID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'PositionLevelName',
    ];

    protected $casts = [
        'PositionLevelID'=>'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    public function positions()
    {
        return $this->hasMany(Position::class, 'PositionLevelID', 'PositionLevelID');
    }
}
