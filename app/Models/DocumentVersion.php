<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $table = 'DocumentVersion';
    
    // Composite primary key
    protected $primaryKey = ['DocumentManagementID', 'VersionNo'];
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'DocumentManagementID',
        'VersionNo',
        'DocumentPath',
        'DocumentUrl',
        'AtTimeStamp',
    ];

    protected $casts = [
        'DocumentManagementID' => 'integer',
        'VersionNo' => 'integer',
        'AtTimeStamp' => 'integer',
    ];

    /**
     * Set the keys for a save update query.
     * For composite primary key support
     */
    protected function setKeysForSaveQuery($query)
    {
        $keys = $this->getKeyName();
        if (!is_array($keys)) {
            return parent::setKeysForSaveQuery($query);
        }

        foreach ($keys as $keyName) {
            $query->where($keyName, '=', $this->getKeyForSaveQuery($keyName));
        }

        return $query;
    }

    /**
     * Get the value for a given key.
     */
    protected function getKeyForSaveQuery($keyName = null)
    {
        if (is_null($keyName)) {
            $keyName = $this->getKeyName();
        }

        if (isset($this->original[$keyName])) {
            return $this->original[$keyName];
        }

        return $this->getAttribute($keyName);
    }

    /**
     * Relationship to DocumentManagement
     */
    public function documentManagement(): BelongsTo
    {
        return $this->belongsTo(DocumentManagement::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Scope to get specific version
     */
    public function scopeVersion($query, int $documentManagementId, int $versionNo)
    {
        return $query->where('DocumentManagementID', $documentManagementId)
                    ->where('VersionNo', $versionNo);
    }

    /**
     * Scope to get latest version for a document
     */
    public function scopeLatest($query, int $documentManagementId)
    {
        return $query->where('DocumentManagementID', $documentManagementId)
                    ->orderBy('VersionNo', 'desc')
                    ->limit(1);
    }
}