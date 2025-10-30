<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemReference extends Model
{
    protected $table = 'SystemReference';
    protected $primaryKey = 'SystemReferenceID';
    public $timestamps = false;

    protected $fillable = [
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ReferenceName',
        'FieldName',
        'FieldValue',
    ];

    protected $casts = [
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    // Helper method to get JSON field value
    public function getFieldValueAsJson()
    {
        return json_decode($this->FieldValue, true);
    }
}
