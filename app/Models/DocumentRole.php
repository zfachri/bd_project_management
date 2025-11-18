<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRole extends Model
{
    protected $table = 'DocumentRole';
    protected $primaryKey = 'DocumentRoleID';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'DocumentManagementID',
        'OrganizationID',
        'IsDownload',
        'IsComment',
    ];

    protected $casts = [
        'DocumentRoleID' => 'integer',
        'DocumentManagementID' => 'integer',
        'OrganizationID' => 'integer',
        'IsDownload' => 'boolean',
        'IsComment' => 'boolean',
    ];

    /**
     * Relationship to DocumentManagement
     */
    public function documentManagement(): BelongsTo
    {
        return $this->belongsTo(DocumentManagement::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to Organization
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    /**
     * Scope to get roles with download permission
     */
    public function scopeCanDownload($query)
    {
        return $query->where('IsDownload', true);
    }

    /**
     * Scope to get roles with comment permission
     */
    public function scopeCanComment($query)
    {
        return $query->where('IsComment', true);
    }
}