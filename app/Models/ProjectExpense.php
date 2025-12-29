<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectExpense extends Model
{
    protected $table = 'ProjectExpense';
    protected $primaryKey = 'ProjectExpenseID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectExpenseID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectID',
        'ExpenseDate',
        'ExpenseNote',
        'CurrencyCode',
        'ExpenseAmount',
        'IsDelete',
        'IsCheck',
    ];

    protected $casts = [
        'IsDelete' => 'boolean',
        'IsCheck' => 'boolean',
        'ExpenseAmount' => 'decimal:0',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'ProjectID', 'ProjectID');
    }

    public function files()
    {
        return $this->hasMany(ProjectExpenseFile::class, 'ProjectExpenseID', 'ProjectExpenseID');
    }
}