<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectStatus extends Model
{
    public const CODE_VOID = '00';
    public const CODE_NEW = '10';
    public const CODE_ON_PROGRESS = '11';
    public const CODE_HOLD = '12';
    public const CODE_COMPLETED = '99';

    public const STATUS_NAMES = [
        self::CODE_VOID => 'VOID / TERMINATED',
        self::CODE_NEW => 'NEW',
        self::CODE_ON_PROGRESS => 'ON-PROGRESS',
        self::CODE_HOLD => 'HOLD',
        self::CODE_COMPLETED => 'COMPLETED',
    ];

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

    public static function nameFromCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::STATUS_NAMES[$code] ?? null;
    }

    public function getProjectStatusNameAttribute(): ?string
    {
        return self::nameFromCode($this->ProjectStatusCode);
    }
}
