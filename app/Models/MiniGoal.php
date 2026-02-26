<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MiniGoal extends Model
{
    protected $table = 'MiniGoal';
    protected $primaryKey = 'MiniGoalID';
    public $timestamps = false;

    protected $fillable = [
        'MiniGoalID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectID',
        'SequenceNo',
        'MiniGoalDescription',
        'MiniGoalCategoryCode',
        'MiniGoalFirstPrefixCode',
        'MiniGoalLastPrefixCode',
        'TargetValue',
        'ActualValue',
        'IsDelete',
    ];

    protected $casts = [
        'IsDelete' => 'boolean',
        'TargetValue' => 'double',
        'ActualValue' => 'double',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'ProjectID', 'ProjectID');
    }

    /**
     * Get category display name
     */
    public function getCategoryNameAttribute()
    {
        $categories = [
            '1' => 'Currency ($)',
            '2' => 'Percentage (%)',
            '3' => 'Quantity (#)',
        ];

        return $categories[$this->MiniGoalCategoryCode] ?? 'Unknown';
    }
}