<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class LeadActivity extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'lead_id', 'user_id', 'type', 'description', 'activity_date',
    ];

    protected $casts = [
        'activity_date' => 'datetime',
    ];

    public function lead() { return $this->belongsTo(Lead::class); }
    public function user() { return $this->belongsTo(User::class); }
}
