<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QualityControl extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'batch_id',
        'company_id',
        'status',
        'inspected_by_user_id',
        'inspected_at',
        'approved_by_user_id',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function inspectedByUser()
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
