<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectStatus extends Model
{
    protected $table = 'ProjectStatus';
    protected $primaryKey = 'ProjectID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectID',
        'ProjectStatusCode',
        'ProjectStatusReason',
        'TotalMember',
        'TotalTaskPriority1',
        'TotalTaskPriority2',
        'TotalTaskPriority3',
        'TotalTask',
        'TotalTaskProgress1',
        'TotalTaskProgress2',
        'TotalTaskProgress3',
        'TotalTaskChecked',
        'TotalExpense',
        'TotalExpenseChecked',
        'AccumulatedExpense',
        'LastTaskUpdateAtTimeStamp',
        'LastTaskUpdateByUserID',
        'LastExpenseUpdateAtTimeStamp',
        'LastExpenseUpdateByUserID',
    ];

    protected $casts = [
        'AccumulatedExpense' => 'decimal:0',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'ProjectID', 'ProjectID');
    }
}