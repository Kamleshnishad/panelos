<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionRun extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'run_no', 'status', 'signature', 'label',
        'planned_sqm', 'notes', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'planned_sqm'  => 'decimal:2',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function batches() { return $this->hasMany(ProductionBatch::class, 'run_id'); }

    public function scopeByStatus($query, string $status) { return $query->where('status', $status); }
}
