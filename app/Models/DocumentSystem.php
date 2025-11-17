<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSystem extends Model
{
    protected $table = 'DocumentSystem';
    protected $primaryKey = 'DocumentID';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'DocumentID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ModuleName',
        'ModuleID',
        'FileName',
        'FilePath',
        'Note',
        'IsDelete',
    ];

    protected $casts = [
        'DocumentID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'ModuleID' => 'integer',
        'IsDelete' => 'boolean',
    ];

    /**
     * Relationship to User model
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ByUserID');
    }

    /**
     * Scope to exclude soft deleted records
     */
    public function scopeActive($query)
    {
        return $query->where('IsDelete', false);
    }

    /**
     * Scope to get documents by module
     */
    public function scopeByModule($query, string $moduleName, int $moduleId)
    {
        return $query->where('ModuleName', $moduleName)
                    ->where('ModuleID', $moduleId);
    }
}