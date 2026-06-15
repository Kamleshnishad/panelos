<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    public $timestamps = false; // only created_at, set manually

    protected $fillable = [
        'company_id', 'user_id', 'user_name', 'action',
        'auditable_type', 'auditable_id', 'label', 'before', 'after', 'ip', 'created_at',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
