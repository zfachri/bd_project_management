<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'AuditLog';
    protected $primaryKey = 'AuditLogID';
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ReferenceTable',
        'ReferenceRecordID',
        'Data',
        'Note',
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'ReferenceRecordID' => 'integer',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }
}
