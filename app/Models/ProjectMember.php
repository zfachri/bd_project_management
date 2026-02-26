<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
    protected $table = 'ProjectMember';
    protected $primaryKey = 'ProjectMemberID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'ProjectMemberID',
        'ProjectID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'UserID',
        'IsActive',
        'IsOwner',
        'Title',
    ];

    protected $casts = [
        'IsActive' => 'boolean',
        'IsOwner' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'ProjectID', 'ProjectID');
    }

    public function assignments()
    {
        return $this->hasMany(ProjectAssignMember::class, 'ProjectMemberID', 'ProjectMemberID');
    }   

     public function user()
    {
        return $this->belongsTo(User::class,'UserID','UserID');
    }
}