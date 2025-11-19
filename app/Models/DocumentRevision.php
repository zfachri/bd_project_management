<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'DocumentRevisionID' => 'integer',
        'DocumentManagementID' => 'integer',
        'ByUserID' => 'integer',
        'NotesByUserID' => 'integer',
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
}