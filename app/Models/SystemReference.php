<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemReference extends Model
{
    protected $table = 'SystemReference';
    protected $primaryKey = 'SystemReferenceID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'SystemReferenceID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'ReferenceName',
        'FieldName',
        'FieldValue',
    ];

    protected $casts = [
        'SystemReferenceID'=> 'integer',
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
