<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'invoice_id',
        'type',
        'phone_number',
        'message',
        'success',
        'error_message',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
