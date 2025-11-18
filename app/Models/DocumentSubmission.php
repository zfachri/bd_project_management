<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSubmission extends Model
{
    protected $table = 'DocumentSubmission';
    protected $primaryKey = 'DocumentSubmission';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'ByUserID',
        'OrganizationID',
        'Comment',
        'Status',
        'Notes',
        'NotesByUserID',
    ];

    protected $casts = [
        'DocumentSubmission' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'NotesByUserID' => 'integer',
    ];

    /**
     * Relationship to User (submitter)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    /**
     * Relationship to Organization
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
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
     * Scope to get pending requests
     */
    public function scopePending($query)
    {
        return $query->where('Status', 'request');
    }

    /**
     * Scope to get approved submissions
     */
    public function scopeApproved($query)
    {
        return $query->where('Status', 'approve');
    }

    /**
     * Scope to get declined submissions
     */
    public function scopeDeclined($query)
    {
        return $query->where('Status', 'decline');
    }
}