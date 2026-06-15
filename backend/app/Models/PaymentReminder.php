<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'invoice_id',
        'reminder_type',
        'reminder_count',
        'last_reminded_at',
        'next_reminder_at',
        'is_paid',
    ];

    protected $casts = [
        'last_reminded_at' => 'datetime',
        'next_reminder_at' => 'datetime',
        'is_paid' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopePending($query)
    {
        return $query->where('is_paid', false)
            ->where('next_reminder_at', '<=', now());
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
