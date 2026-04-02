<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRevision extends Model
{
    protected $table = 'DocumentRevision';
    protected $primaryKey = 'DocumentRevisionID';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'DocumentRevisionID',
        'DocumentManagementID',
        'ByUserID',
        'Comment',
        'Status',
        'Notes',
        'NotesByUserID',
        'VersionNo',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'DocumentRevisionID' => 'integer',
        'DocumentManagementID' => 'integer',
        'ByUserID' => 'integer',
        'NotesByUserID' => 'integer',
        'VersionNo' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * Relationship to DocumentManagement
     */
    public function documentManagement(): BelongsTo
    {
        return $this->belongsTo(DocumentManagement::class, 'DocumentManagementID', 'DocumentManagementID');
    }

    /**
     * Relationship to User (requester)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    /**
     * Relationship to User (notes by)
     */
    public function notesBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'NotesByUserID', 'UserID');
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('Status', $status);
    }

    /**
     * Scope to get pending revisions
     */
    public function scopePending($query)
    {
        return $query->where('Status', 'request');
    }

    /**
     * Scope to get approved revisions
     */
    public function scopeApproved($query)
    {
        return $query->where('Status', 'approve');
    }

    /**
     * Scope to get declined revisions
     */
    public function scopeDeclined($query)
    {
        return $query->where('Status', 'decline');
    }

    /**
     * Generate DocumentRevisionID with format: YYYYMMDD + 5-digit sequence.
     * Example: 2026033100001, 2026033100002, ...
     */
    public static function generateDailyDocumentRevisionId(?Carbon $date = null): int
    {
        $targetDate = ($date ?? Carbon::now())->startOfDay();
        $prefix = (int) $targetDate->format('Ymd');
        $base = $prefix * 100000;

        $maxId = self::query()
            ->whereBetween('DocumentRevisionID', [$base, $base + 99999])
            ->lockForUpdate()
            ->max('DocumentRevisionID');

        return $maxId ? ((int) $maxId + 1) : ($base + 1);
    }
}
