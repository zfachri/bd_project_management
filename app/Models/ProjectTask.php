<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTask extends Model
{
    protected $table = 'ProjectTask';
    protected $primaryKey = 'ProjectTaskID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectTaskID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectID',
        'ParentProjectTaskID',
        'SequenceNo',
        'PriorityCode',
        'TaskDescription',
        'StartDate',
        'EndDate',
        'ProgressCode',
        'Note',
        'IsDelete',
        'IsCheck',
    ];

    protected $casts = [
        'IsDelete' => 'boolean',
        'IsCheck' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'ProjectID', 'ProjectID');
    }

    public function files()
    {
        return $this->hasMany(ProjectTaskFile::class, 'ProjectTaskID', 'ProjectTaskID');
    }

    public function assignments()
    {
        return $this->hasMany(ProjectAssignMember::class, 'ProjectTaskID', 'ProjectTaskID');
    }
}