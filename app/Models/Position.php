<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $table = 'Position';
    protected $primaryKey = 'PositionID';
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'OrganizationID',
        'ParentPositionID',
        'LevelNo',
        'IsChild',
        'PositionName',
        'PositionLevelID',
        'RequirementQuantity',
        'IsActive',
        'IsDelete',
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'ParentPositionID' => 'integer',
        'LevelNo' => 'integer',
        'IsChild' => 'boolean',
        'PositionLevelID' => 'integer',
        'RequirementQuantity' => 'integer',
        'IsActive' => 'boolean',
        'IsDelete' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    public function parent()
    {
        return $this->belongsTo(Position::class, 'ParentPositionID', 'PositionID');
    }

    public function children()
    {
        return $this->hasMany(Position::class, 'ParentPositionID', 'PositionID');
    }

    public function positionLevel()
    {
        return $this->belongsTo(PositionLevel::class, 'PositionLevelID', 'PositionLevelID');
    }

    public function jobDescriptions()
    {
        return $this->hasMany(JobDescription::class, 'PositionID', 'PositionID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive', true)->where('IsDelete', false);
    }
}
