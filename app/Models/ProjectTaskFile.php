<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTaskFile extends Model
{
    protected $table = 'ProjectTaskFile';
    protected $primaryKey = 'ProjectTaskFileID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectTaskFileID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectID',
        'ProjectTaskID',
        'OriginalFileName',
        'ConvertedFileName',
        'DocumentPath',
        'DocumentUrl',
        'DocumentOriginalPath',
        'DocumentOriginalUrl',
        'IsDelete',
    ];

    protected $casts = [
        'IsDelete' => 'boolean',
    ];

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'ProjectTaskID', 'ProjectTaskID');
    }

    public function uploader()
{
    return $this->belongsTo(User::class, 'ByUserID', 'UserID');
}
}