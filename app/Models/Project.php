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
        'ProjectName',
        'CurrencyCode',
        'BudgetAmount',
        'IsDelete',
        'StartDate',
        'EndDate',
        'PriorityCode',
        'DocumentPath',
        'DocumentUrl',
        'DocumentOriginalPath',
        'DocumentOriginalUrl',
    ];

    protected $casts = [
        'IsChild' => 'boolean',
        'IsDelete' => 'boolean',
        'BudgetAmount' => 'decimal:2',
        'StartDate'  => 'date:Y-m-d',
        'EndDate'    => 'date:Y-m-d',
    ];

    public function setBudgetAmountAttribute($value): void
    {
        $this->attributes['BudgetAmount'] = round((float) $value, 2);
    }

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

    public function miniGoals() // NEW
    {
        return $this->hasMany(MiniGoal::class, 'ProjectID', 'ProjectID')->where('IsDelete', false);
    }

    /**
     * Get the single owner of the project
     */
    public function owner()
    {
        return $this->hasOne(ProjectMember::class, 'ProjectID', 'ProjectID')
            ->where('IsOwner', true)
            ->where('IsActive', true);
    }
}
