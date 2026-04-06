<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAssignMember extends Model
{
    protected $table = 'ProjectAssignMember';
    protected $primaryKey = 'ProjectAssignMemberID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectAssignMemberID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectMemberID',
        'ProjectTaskID',
    ];

    public function member()
    {
        return $this->belongsTo(ProjectMember::class, 'ProjectMemberID', 'ProjectMemberID');
    }

    public function projectMember()
    {
        return $this->belongsTo(ProjectMember::class, 'ProjectMemberID', 'ProjectMemberID');
    }

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'ProjectTaskID', 'ProjectTaskID');
    }
}