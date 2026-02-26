<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectExpenseFile extends Model
{
    protected $table = 'ProjectExpenseFile';
    protected $primaryKey = 'ProjectExpenseFileID';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ProjectExpenseFileID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ProjectID',
        'ProjectExpenseID',
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

    public function expense()
    {
        return $this->belongsTo(ProjectExpense::class, 'ProjectExpenseID', 'ProjectExpenseID');
    }
        public function uploader()
    {
        return $this->belongsTo(User::class,'ByUserID','UserID');
    }
}