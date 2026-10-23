<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductionStage extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'sequence',
        'description',
        'is_active',
        'requires_approval',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
    ];

    /**
     * Relationships
     */

    public function stageLogs()
    {
        return $this->hasMany(BatchStageLog::class);
    }

    /**
     * Scopes
     */

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sequence');
    }
}
