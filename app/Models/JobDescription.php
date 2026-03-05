<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class JobDescription extends Model
{
    protected $table = 'JobDescription';
    protected $primaryKey = 'RecordID';
    public $timestamps = false;
    public $incrementing = false; // <-- nonaktifkan auto increment

    protected $fillable = [
        'RecordID',
        'AtTimeStamp',
        'ByUserID',
        'OperationCode',
        'OrganizationID',
        'PositionID',
        'JobDescription',
        'MainTaskDescription',
        'MainTaskMeasurement',
        'InternalRelationshipDescription',
        'InternalRelationshipObjective',
        'ExternalRelationshipDescription',
        'ExternalRelationshipObjective',
        'TechnicalCompetency',
        'SoftCompetency',
        'IsDelete',
    ];

    protected $casts = [
        'RecordID'=>'integer',
        'AtTimeStamp' => 'integer',
        'ByUserID' => 'integer',
        'OrganizationID' => 'integer',
        'PositionID' => 'integer',
        'IsDelete' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->beforeInsert();
        });
    }

    public function beforeInsert(): void
    {
        if (empty($this->RecordID)) {
            $this->RecordID = Carbon::now()->timestamp . random_numbersu(5);
        }
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'ByUserID', 'UserID');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OrganizationID', 'OrganizationID');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'PositionID', 'PositionID');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsDelete', false);
    }
}
