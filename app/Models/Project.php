<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'Project';
    protected $primaryKey = 'ProjectID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'ProjectID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ParentProjectID',
        'LevelNo',
        'IsChild',
        'ProjectCategoryID',
        'ProjectDescription',
        'CurrencyCode',
        'BudgetAmount',
        'IsDelete',
        'StartDate',
        'EndDate',
        'PriorityCode',
    ];

    protected $casts = [
        'IsChild' => 'boolean',
        'IsDelete' => 'boolean',
        'BudgetAmount' => 'decimal:0',
    ];

    public function status()
    {
        return $this->hasOne(ProjectStatus::class, 'ProjectID', 'ProjectID');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class, 'ProjectID', 'ProjectID');
    }

    public function tasks()
    {
        return $this->hasMany(ProjectTask::class, 'ProjectID', 'ProjectID');
    }

    public function expenses()
    {
        return $this->hasMany(ProjectExpense::class, 'ProjectID', 'ProjectID');
    }
}