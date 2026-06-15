<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HsnCode extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'code',
        'description',
        'category',
        'gst_rate',
        'cess_rate',
        'is_active',
    ];

    protected $casts = [
        'gst_rate' => 'float',
        'cess_rate' => 'float',
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

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }
}
