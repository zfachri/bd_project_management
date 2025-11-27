<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaciActivity extends Model
{
    protected $table = 'RaciActivity';
    protected $primaryKey = 'RaciActivityID';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'RaciActivityID',
        'DocumentManagementID',
        'Activity',
        'PIC',
        'Status',
    ];

    protected $casts = [
        'RaciActivityID' => 'integer',
        'DocumentManagementID' => 'integer',
        'PIC' => 'integer',
    ];

    /**
     * Relationship to DocumentManagement
     */
    public function documentManagement(): BelongsTo
    {
        return $this->belongsTo(DocumentManagement::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to Position (PIC)
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'PIC', 'PositionID');
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('Status', $status);
    }

    /**
     * Scope to filter by Position (PIC)
     */
    public function scopeByPosition($query, int $positionId)
    {
        return $query->where('PIC', $positionId);
    }
}