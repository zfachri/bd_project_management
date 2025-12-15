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

    // Konstanta untuk Status RACI
    const STATUS_INFORMED = 'Informed';
    const STATUS_ACCOUNTABLE = 'Accountable';
    const STATUS_CONSULTED = 'Consulted';
    const STATUS_RESPONSIBLE = 'Responsible';

    public static function getStatuses()
    {
        return [
            self::STATUS_INFORMED,
            self::STATUS_ACCOUNTABLE,
            self::STATUS_CONSULTED,
            self::STATUS_RESPONSIBLE,
        ];
    }

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