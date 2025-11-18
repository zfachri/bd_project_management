<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentManagement extends Model
{
    protected $table = 'DocumentManagement';
    protected $primaryKey = 'DocumentManagementID';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'DocumentName',
        'DocumentType',
        'Description',
        'Notes',
        'OrganizationID',
        'LatestVersionNo',
    ];

    protected $casts = [
        'DocumentManagementID' => 'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'LatestVersionNo' => 'integer',
    ];

    protected $attributes = [
        'LatestVersionNo' => 1,
    ];

    /**
     * Relationship to User model (creator)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    /**
     * Relationship to Organization model
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    /**
     * Relationship to RaciActivity (only for DocumentType = 'RACI')
     */
    public function raciActivities(): HasMany
    {
        return $this->hasMany(RaciActivity::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to DocumentRole
     */
    public function documentRoles(): HasMany
    {
        return $this->hasMany(DocumentRole::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to DocumentRevision
     */
    public function documentRevisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to DocumentVersion
     */
    public function documentVersions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Get latest document version
     */
    public function latestVersion(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'DocumentManagementID', 'DocumentManagementID')
                    ->orderBy('VersionNo', 'desc')
                    ->limit(1);
    }

    /**
     * Scope to get documents by organization
     */
    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('OrganizationID', $organizationId);
    }

    /**
     * Scope to get RACI documents
     */
    public function scopeRaciType($query)
    {
        return $query->where('DocumentType', 'RACI');
    }
}