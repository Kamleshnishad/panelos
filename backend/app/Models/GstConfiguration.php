<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GstConfiguration extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'state_code',
        'state_name',
        'gstin',
        'registration_type',
        'is_primary',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}
